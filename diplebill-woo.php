<?php
/**
 * Plugin Name: DipleBill WooCommerce Connector
 * Plugin URI:  https://github.com/alexandersr92/diplebill-woo
 * Description: Conector oficial para sincronizar productos, inventario y ventas entre WooCommerce y DipleBill POS.
 * Version:     1.0.0
 * Author:      DipleBill Team
 * Author URI:  https://github.com/alexandersr92/diplebill-woo
 * License:     GPL2
 * Text Domain: diplebill-woo
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Permitir Autenticación Básica sobre HTTP en entornos locales/desarrollo
if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/wc/') !== false) {
    if (isset($_GET['consumer_key']) || isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTPS'] = 'on';
    }
}

/**
 * Verificar dependencias
 */
function diplebill_woo_check_dependencies() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'diplebill_woo_missing_wc_notice');
        return false;
    }
    return true;
}

function diplebill_woo_missing_wc_notice() {
    echo '<div class="error"><p>';
    echo esc_html__('El plugin DipleBill WooCommerce Connector requiere que WooCommerce esté instalado y activo.', 'diplebill-woo');
    echo '</p></div>';
}

/**
 * Inicialización de Hooks principales
 */
function diplebill_woo_init() {
    if (!diplebill_woo_check_dependencies()) {
        return;
    }

    // Registrar menú en WooCommerce
    add_action('admin_menu', 'diplebill_woo_add_settings_menu');

    // Registrar campos personalizados de Stock de Seguridad
    add_action('woocommerce_product_options_inventory_product_data', 'diplebill_woo_add_product_safety_stock_field');
    add_action('woocommerce_process_product_meta', 'diplebill_woo_save_product_safety_stock_field');

    // Añadir campo a las Categorías
    add_action('product_cat_add_form_fields', 'diplebill_woo_add_category_safety_stock_field', 10, 2);
    add_action('product_cat_edit_form_fields', 'diplebill_woo_edit_category_safety_stock_field', 10, 2);
    add_action('created_product_cat', 'diplebill_woo_save_category_safety_stock_field', 10, 2);
    add_action('edited_product_cat', 'diplebill_woo_save_category_safety_stock_field', 10, 2);

    // Filtros para aplicar el Stock de Seguridad local
    add_filter('woocommerce_product_get_stock_quantity', 'diplebill_woo_apply_safety_stock', 10, 2);
    add_filter('woocommerce_product_variation_get_stock_quantity', 'diplebill_woo_apply_safety_stock', 10, 2);
    add_filter('woocommerce_product_is_in_stock', 'diplebill_woo_apply_safety_stock_status', 10, 2);

    // Enviar orden de venta al completar pago
    add_action('woocommerce_order_status_processing', 'diplebill_woo_sync_order_to_diplebill');
    add_action('woocommerce_order_status_completed', 'diplebill_woo_sync_order_to_diplebill');

    // Interceptar búsquedas de la API REST de WooCommerce para buscar por SKU mapeado
    add_filter('woocommerce_rest_product_object_query', 'diplebill_woo_rest_product_by_mapped_sku', 10, 2);
    add_filter('woocommerce_rest_product_variation_object_query', 'diplebill_woo_rest_product_by_mapped_sku', 10, 2);
}
add_action('plugins_loaded', 'diplebill_woo_init');

/**
 * Permitir buscar productos en la API REST de WooCommerce por el SKU mapeado
 */
function diplebill_woo_rest_product_by_mapped_sku($args, $request) {
    if (isset($request['sku']) && !empty($request['sku'])) {
        $sku = sanitize_text_field($request['sku']);
        $args['meta_query'] = [
            'relation' => 'OR',
            [
                'key'     => '_sku',
                'value'   => $sku,
                'compare' => '='
            ],
            [
                'key'     => '_diplebill_mapped_sku',
                'value'   => $sku,
                'compare' => '='
            ]
        ];
        unset($args['sku']);
    }
    return $args;
}

/**
 * Registrar página de administración
 */
function diplebill_woo_add_settings_menu() {
    add_submenu_page(
        'woocommerce',
        __('DipleBill Conector', 'diplebill-woo'),
        __('DipleBill Conector', 'diplebill-woo'),
        'manage_options',
        'diplebill-woo-connector',
        'diplebill_woo_render_settings_page'
    );
}

/**
 * Obtener la URL de la API de DipleBill
 */
function diplebill_woo_get_api_url() {
    if (defined('DIPLEBILL_API_URL')) {
        return rtrim(DIPLEBILL_API_URL, '/');
    }
    return 'http://inventory_api.test';
    // return 'https://api.diplebill.com';
}

/**
 * Descargar todos los productos de DipleBill POS manejando paginación
 */
function diplebill_woo_fetch_all_products() {
    $api_url = diplebill_woo_get_api_url();
    $token = get_option('diplebill_api_token', '');
    if (empty($token)) {
        return [];
    }

    $all_products = [];
    $page = 1;
    $has_more = true;

    while ($has_more && $page < 15) { // Límite de 15 páginas (1500 productos) para evitar timeouts
        $response = wp_remote_get($api_url . '/api/v1/products?per_page=100&page=' . $page, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json'
            ],
            'timeout' => 20
        ]);

        if (is_wp_error($response)) {
            break;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            break;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $products = isset($data['data']) ? $data['data'] : [];
        if (empty($products)) {
            $has_more = false;
        } else {
            $filtered_products = [];
            foreach ($products as $prod) {
                if (isset($prod['sku']) && strpos(strtoupper($prod['sku']), 'WOO-') === 0) {
                    continue;
                }
                $filtered_products[] = $prod;
            }
            $all_products = array_merge($all_products, $filtered_products);
            
            $meta = isset($data['meta']) ? $data['meta'] : [];
            $current_page = isset($meta['current_page']) ? $meta['current_page'] : $page;
            $last_page = isset($meta['last_page']) ? $meta['last_page'] : $page;
            
            if ($current_page >= $last_page) {
                $has_more = false;
            } else {
                $page++;
            }
        }
    }

    return $all_products;
}

/**
 * Renderizar la página de configuración
 */
function diplebill_woo_render_settings_page() {
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
    
    // Procesar envíos y acciones comunes
    $connection_error = '';
    $connection_success = false;

    // Procesar envío de pestaña general
    if ($current_tab === 'general' && isset($_POST['diplebill_save_settings']) && check_admin_referer('diplebill_woo_settings_nonce')) {
        update_option('diplebill_api_token', sanitize_text_field($_POST['diplebill_api_token']));
        update_option('diplebill_safety_stock_default', intval($_POST['diplebill_safety_stock_default']));
        
        $token = sanitize_text_field($_POST['diplebill_api_token']);
        $store_id = isset($_POST['diplebill_store_id']) ? sanitize_text_field($_POST['diplebill_store_id']) : '';
        $inventory_id = isset($_POST['diplebill_inventory_id']) ? sanitize_text_field($_POST['diplebill_inventory_id']) : '';
        $woo_consumer_key = isset($_POST['diplebill_woo_consumer_key']) ? sanitize_text_field($_POST['diplebill_woo_consumer_key']) : '';
        $woo_consumer_secret = isset($_POST['diplebill_woo_consumer_secret']) ? sanitize_text_field($_POST['diplebill_woo_consumer_secret']) : '';

        update_option('diplebill_store_id', $store_id);
        update_option('diplebill_inventory_id', $inventory_id);
        update_option('diplebill_woo_consumer_key', $woo_consumer_key);
        update_option('diplebill_woo_consumer_secret', $woo_consumer_secret);

        // Si tenemos todos los datos necesarios, registrar o actualizar la integración en la API de Dipledev
        if (!empty($token) && !empty($store_id) && !empty($inventory_id) && !empty($woo_consumer_key) && !empty($woo_consumer_secret)) {
            $api_url = diplebill_woo_get_api_url();
            $register_response = wp_remote_post(rtrim($api_url, '/') . '/api/v1/woocommerce/integration', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json'
                ],
                'body'    => json_encode([
                    'store_id' => $store_id,
                    'inventory_id' => $inventory_id,
                    'woo_store_url' => site_url(),
                    'woo_consumer_key' => $woo_consumer_key,
                    'woo_consumer_secret' => $woo_consumer_secret,
                    'status' => true
                ]),
                'timeout' => 20
            ]);

            if (is_wp_error($register_response)) {
                echo '<div class="error"><p>Configuración local guardada, pero falló el registro en la API de DipleBill: ' . esc_html($register_response->get_error_message()) . '</p></div>';
            } else {
                $code = wp_remote_retrieve_response_code($register_response);
                if ($code === 200 || $code === 201) {
                    echo '<div class="updated"><p>Configuración general guardada y registrada con éxito en la API de DipleBill.</p></div>';
                } else {
                    $body = wp_remote_retrieve_body($register_response);
                    echo '<div class="error"><p>Configuración local guardada, pero la API de DipleBill rechazó las credenciales (HTTP ' . $code . '): ' . esc_html($body) . '</p></div>';
                }
            }
        } else {
            echo '<div class="updated"><p>Configuración general guardada localmente.</p></div>';
        }
    }

    // Procesar acción de conectar y cargar catálogos
    if ($current_tab === 'general' && isset($_POST['diplebill_test_connection']) && check_admin_referer('diplebill_woo_settings_nonce')) {
        $api_url = diplebill_woo_get_api_url();
        $token = sanitize_text_field($_POST['diplebill_api_token']);

        if (!empty($token)) {
            // Obtener tiendas
            $stores_response = wp_remote_get($api_url . '/api/v1/stores', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json'
                ],
                'timeout' => 15
            ]);

            // Obtener inventarios
            $inventories_response = wp_remote_get($api_url . '/api/v1/inventories', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json'
                ],
                'timeout' => 15
            ]);

            if (is_wp_error($stores_response) || is_wp_error($inventories_response)) {
                $connection_error = 'Error al comunicarse con la API de DipleBill en ' . esc_url($api_url . '/api/v1/stores') . ': ' . 
                    (is_wp_error($stores_response) ? $stores_response->get_error_message() : $inventories_response->get_error_message());
            } else {
                $stores_code = wp_remote_retrieve_response_code($stores_response);
                $stores_body = wp_remote_retrieve_body($stores_response);
                $inventories_body = wp_remote_retrieve_body($inventories_response);

                if ($stores_code === 200) {
                    $stores_data = json_decode($stores_body, true);
                    $inventories_data = json_decode($inventories_body, true);

                    // Guardar listados locales
                    $stores_list = isset($stores_data['data']) ? $stores_data['data'] : $stores_data;
                    $inventories_list = isset($inventories_data['data']) ? $inventories_data['data'] : $inventories_data;

                    update_option('diplebill_stores_cache', $stores_list);
                    update_option('diplebill_inventories_cache', $inventories_list);
                    $connection_success = true;
                } else {
                    $connection_error = 'La API de DipleBill en ' . esc_url($api_url . '/api/v1/stores') . ' retornó código HTTP: ' . $stores_code . '. Verifica tus credenciales.';
                }
            }
        } else {
            $connection_error = 'Debes especificar el Token antes de conectar.';
        }
    }

    // Procesar envío de mapeo
    if ($current_tab === 'import' && isset($_POST['diplebill_save_mappings']) && check_admin_referer('diplebill_woo_mappings_nonce')) {
        $mappings = isset($_POST['diplebill_map']) ? $_POST['diplebill_map'] : [];
        foreach ($mappings as $woo_id => $mapped_sku) {
            update_post_meta(intval($woo_id), '_diplebill_mapped_sku', sanitize_text_field($mapped_sku));
        }
        echo '<div class="updated"><p>Mapeo de productos guardado correctamente.</p></div>';
    }

    // Procesar acciones de importación
    $cached_products = get_option('diplebill_products_cache', []);
    if ($current_tab === 'import') {
        // 1. Sincronizar catálogo local
        if (isset($_POST['diplebill_sync_catalog']) && check_admin_referer('diplebill_woo_import_nonce')) {
            $products = diplebill_woo_fetch_all_products();
            if (!empty($products)) {
                update_option('diplebill_products_cache', $products);
                $cached_products = $products;
                echo '<div class="updated"><p>Catálogo de DipleBill cargado exitosamente en caché. Se encontraron ' . count($products) . ' productos.</p></div>';
            } else {
                echo '<div class="error"><p>No se pudieron obtener productos de la API de DipleBill. Revisa tu token y conexión.</p></div>';
            }
        }

        // 2. Importar en WooCommerce
        if (isset($_POST['diplebill_run_import']) && check_admin_referer('diplebill_woo_import_nonce')) {
            if (empty($cached_products)) {
                echo '<div class="error"><p>Primero debes sincronizar el catálogo de DipleBill para cargarlo en caché.</p></div>';
            } else {
                $imported_count = 0;
                $updated_count = 0;
                $inventory_id = get_option('diplebill_inventory_id', '');

                foreach ($cached_products as $dp) {
                    $sku = $dp['sku'];
                    if (empty($sku)) {
                        continue;
                    }

                    // Buscar si existe un producto por SKU estándar
                    $product_id = wc_get_product_id_by_sku($sku);
                    
                    // Si no existe, buscar por el meta key _diplebill_mapped_sku
                    if (!$product_id) {
                        $posts = get_posts([
                            'post_type' => ['product', 'product_variation'],
                            'meta_query' => [
                                [
                                    'key' => '_diplebill_mapped_sku',
                                    'value' => $sku,
                                    'compare' => '='
                                ]
                            ],
                            'fields' => 'ids',
                            'posts_per_page' => 1
                        ]);
                        if (!empty($posts)) {
                            $product_id = $posts[0];
                        }
                    }

                    // Obtener stock real correspondiente al inventario seleccionado
                    $stock_qty = 0;
                    if (!empty($dp['inventory'])) {
                        foreach ($dp['inventory'] as $inv_detail) {
                            if ($inv_detail['inventory_id'] === $inventory_id) {
                                $stock_qty = intval($inv_detail['quantity']);
                                break;
                            }
                        }
                    }

                    if ($product_id) {
                        // Existe: Actualizar precio y stock
                        $product = wc_get_product($product_id);
                        $product->set_regular_price($dp['price']);
                        $product->set_manage_stock(true);
                        $product->set_stock_quantity($stock_qty);
                        $product->save();
                        $updated_count++;
                    } else {
                        // No existe: Crear nuevo
                        $post_id = wp_insert_post([
                            'post_title'    => $dp['name'],
                            'post_content'  => $dp['description'] ?? '',
                            'post_status'   => 'publish',
                            'post_type'     => 'product',
                        ]);

                        if ($post_id) {
                            $product = new WC_Product_Simple($post_id);
                            $product->set_sku($sku);
                            $product->set_regular_price($dp['price']);
                            $product->set_manage_stock(true);
                            $product->set_stock_quantity($stock_qty);
                            $product->save();
                            $imported_count++;
                        }
                    }
                }

                echo '<div class="updated"><p>Proceso de importación finalizado con éxito: ' . $imported_count . ' productos creados y ' . $updated_count . ' productos actualizados en WooCommerce.</p></div>';
            }
        }
    }

    $api_token = get_option('diplebill_api_token', '');
    $safety_stock_default = get_option('diplebill_safety_stock_default', '0');
    $selected_store = get_option('diplebill_store_id', '');
    $selected_inventory = get_option('diplebill_inventory_id', '');
    $woo_consumer_key = get_option('diplebill_woo_consumer_key', '');
    $woo_consumer_secret = get_option('diplebill_woo_consumer_secret', '');

    $stores = get_option('diplebill_stores_cache', []);
    $inventories = get_option('diplebill_inventories_cache', []);
    ?>
    <div class="wrap">
        <h1>DipleBill WooCommerce Connector</h1>

        <!-- Pestañas -->
        <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
            <a href="?page=diplebill-woo-connector&tab=general" class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>">Ajustes Generales</a>
            <a href="?page=diplebill-woo-connector&tab=import" class="nav-tab <?php echo $current_tab === 'import' ? 'nav-tab-active' : ''; ?>">Importar Catálogo</a>
        </h2>

        <?php if (!empty($connection_error)) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($connection_error); ?></p></div>
        <?php endif; ?>

        <?php if ($connection_success) : ?>
            <div class="notice notice-success is-dismissible"><p>Conexión exitosa. Se han cargado las tiendas e inventarios de DipleBill.</p></div>
        <?php endif; ?>

        <!-- Renderizado de Pestaña General -->
        <?php if ($current_tab === 'general') : ?>
            <form method="post" action="">
                <?php wp_nonce_field('diplebill_woo_settings_nonce'); ?>
                
                <h2 class="title">Credenciales de API</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Token de Acceso Personal (Bearer)</th>
                        <td>
                            <input type="password" name="diplebill_api_token" value="<?php echo esc_attr($api_token); ?>" class="regular-text" required />
                            <p class="description">Token generado en tu cuenta de administrador de DipleBill POS.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="diplebill_test_connection" class="button button-secondary" value="Probar Conexión y Cargar Catálogos" />
                </p>

                <?php if (!empty($stores) || !empty($inventories)) : ?>
                    <h2 class="title">Asociación de Sucursal e Inventario</h2>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Tienda / Sucursal Asignada</th>
                            <td>
                                <select name="diplebill_store_id" class="postform">
                                    <option value="">Selecciona una tienda</option>
                                    <?php foreach ($stores as $store) : ?>
                                        <option value="<?php echo esc_attr($store['id']); ?>" <?php selected($selected_store, $store['id']); ?>>
                                            <?php echo esc_html($store['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Las facturas creadas desde la web se asociarán a esta sucursal.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Inventario de Sincronización</th>
                            <td>
                                <select name="diplebill_inventory_id" class="postform">
                                    <option value="">Selecciona un inventario</option>
                                    <?php foreach ($inventories as $inventory) : ?>
                                        <option value="<?php echo esc_attr($inventory['id']); ?>" <?php selected($selected_inventory, $inventory['id']); ?>>
                                            <?php echo esc_html($inventory['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Las existencias de la web se actualizarán desde este inventario.</p>
                            </td>
                        </tr>
                    </table>

                    <h2 class="title">API de WooCommerce (para Sincronización Bidireccional)</h2>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">WooCommerce Consumer Key</th>
                            <td>
                                <input type="text" name="diplebill_woo_consumer_key" value="<?php echo esc_attr($woo_consumer_key); ?>" class="regular-text" placeholder="ck_..." required />
                                <p class="description">Genera las llaves de lectura/escritura en: WooCommerce &gt; Ajustes &gt; Avanzado &gt; API REST.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">WooCommerce Consumer Secret</th>
                            <td>
                                <input type="password" name="diplebill_woo_consumer_secret" value="<?php echo esc_attr($woo_consumer_secret); ?>" class="regular-text" placeholder="cs_..." required />
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>

                <h2 class="title">Configuración de Stock de Seguridad</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Margen de Seguridad Global</th>
                        <td>
                            <input type="number" min="0" name="diplebill_safety_stock_default" value="<?php echo esc_attr($safety_stock_default); ?>" class="small-text" />
                            <p class="description">Cantidad a restar del stock real. Si el stock real en DipleBill es 10 y el margen es 2, en la web se mostrará 8.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="diplebill_save_settings" class="button button-primary" value="Guardar Cambios" />
                </p>
            </form>
        <?php endif; ?>

        <!-- Renderizado de Pestaña de Importación -->
        <?php if ($current_tab === 'import') : 
            if (empty($selected_store) || empty($selected_inventory)) :
                ?>
                <div class="notice notice-warning inline" style="margin-top: 20px; padding: 15px;">
                    <h3>⚠️ Configuración Requerida</h3>
                    <p>Por favor, configure primero el token de API, la **Sucursal** y el **Inventario** en la pestaña <strong>Ajustes Generales</strong> y guarde los cambios antes de continuar con la importación de productos.</p>
                </div>
                <?php
            else :
                ?>
                <div class="diplebill-import-steps">
                    <!-- Paso 1 -->
                    <div class="diplebill-step-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h2 style="margin-top: 0;">Paso 1: Descargar / Actualizar Catálogo desde DipleBill</h2>
                        <p>Descarga los productos y el stock actual del inventario seleccionado desde la API de DipleBill POS a la caché de WordPress.</p>
                        
                        <table class="form-table" style="margin: 0 0 15px 0;">
                            <tr valign="top">
                                <th scope="row" style="width: 200px; padding: 10px 0;">Productos en Caché Local</th>
                                <td style="padding: 10px 0;">
                                    <strong style="font-size: 16px;"><?php echo count($cached_products); ?> productos</strong> cargados actualmente.
                                </td>
                            </tr>
                        </table>

                        <form method="post" action="">
                            <?php wp_nonce_field('diplebill_woo_import_nonce'); ?>
                            <input type="submit" name="diplebill_sync_catalog" class="button button-secondary button-large" value="Sincronizar Catálogo de DipleBill" />
                        </form>
                    </div>

                    <?php if (!empty($cached_products)) : ?>
                        <!-- Paso 2 -->
                        <div class="diplebill-step-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                            <h2>Paso 2: Mapeo de Productos (Asociación Manual Opcional)</h2>
                            <p>Asocia los productos de tu WooCommerce con el SKU correcto de DipleBill. Si no asocias un producto, se emparejará automáticamente por el SKU por defecto.</p>
                            
                            <?php
                            $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
                            $woo_products = new WP_Query([
                                'post_type' => ['product', 'product_variation'],
                                'posts_per_page' => 20,
                                'paged' => $paged
                            ]);
                            ?>
                            <form method="post" action="">
                                <?php wp_nonce_field('diplebill_woo_mappings_nonce'); ?>
                                <table class="wp-list-table widefat fixed striped" style="margin-bottom: 15px;">
                                    <thead>
                                        <tr>
                                            <th style="width: 40%;">Producto WooCommerce (Web)</th>
                                            <th style="width: 20%;">SKU Web</th>
                                            <th style="width: 40%;">Producto Asociado de DipleBill POS</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($woo_products->have_posts()) : while ($woo_products->have_posts()) : $woo_products->the_post(); 
                                            $product = wc_get_product(get_the_ID());
                                            if (!$product) continue;
                                            $current_mapped = get_post_meta($product->get_id(), '_diplebill_mapped_sku', true);
                                            ?>
                                            <tr>
                                                <td><strong><?php echo esc_html($product->get_name()); ?></strong></td>
                                                <td><code><?php echo esc_html($product->get_sku()); ?></code></td>
                                                <td>
                                                    <select name="diplebill_map[<?php echo $product->get_id(); ?>]" style="max-width: 100%; width: 350px;">
                                                        <option value="">-- No asociado (Usar SKU predeterminado) --</option>
                                                        <?php foreach ($cached_products as $dp) : ?>
                                                            <option value="<?php echo esc_attr($dp['sku']); ?>" <?php selected($current_mapped, $dp['sku']); ?>>
                                                                <?php echo esc_html($dp['name'] . ' (SKU: ' . $dp['sku'] . ')'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php endwhile; wp_reset_postdata(); else : ?>
                                            <tr><td colspan="3">No se encontraron productos en WooCommerce.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                
                                <div class="tablenav" style="display: flex; justify-content: space-between; align-items: center;">
                                    <div class="alignleft actions">
                                        <input type="submit" name="diplebill_save_mappings" class="button button-secondary" value="Guardar Mapeos de esta Página" />
                                    </div>
                                    <div class="tablenav-pages">
                                        <?php
                                        echo paginate_links([
                                            'base' => add_query_arg('paged', '%#%'),
                                            'format' => '',
                                            'prev_text' => __('&laquo; Anterior'),
                                            'next_text' => __('Siguiente &raquo;'),
                                            'total' => $woo_products->max_num_pages,
                                            'current' => $paged
                                        ]);
                                        ?>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Paso 3 -->
                        <div class="diplebill-step-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                            <h2>Paso 3: Crear / Actualizar Productos en WooCommerce</h2>
                            <p>Procesa los productos que están cargados en caché. Si el producto existe (por SKU coincidente o SKU mapeado), se actualizará su precio e inventario físico. Si no existe, se creará uno nuevo publicado.</p>
                            
                            <form method="post" action="">
                                <?php wp_nonce_field('diplebill_woo_import_nonce'); ?>
                                <input type="submit" name="diplebill_run_import" class="button button-primary button-large" value="Ejecutar Importación / Actualización Masiva" />
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; // Cierre del else de configuración
        endif; // Cierre de pestaña import ?>
    </div>
    <?php
}

/**
 * -------------------------------------------------------------
 * GESTIÓN DE STOCK DE SEGURIDAD (PRODUCTO Y CATEGORÍA)
 * -------------------------------------------------------------
 */

/**
 * Agregar campo de Stock de Seguridad en la pestaña de Inventario del Producto
 */
function diplebill_woo_add_product_safety_stock_field() {
    global $product_object;
    woocommerce_wp_text_input([
        'id'          => '_diplebill_safety_stock',
        'label'       => __('Margen de Seguridad', 'woocommerce'),
        'description' => __('Ingresa un valor numérico para anular el margen global de seguridad de stock para este producto.', 'woocommerce'),
        'desc_tip'    => 'true',
        'type'        => 'number',
        'custom_attributes' => ['min' => 0, 'step' => 1]
    ]);
}

/**
 * Guardar el stock de seguridad del producto
 */
function diplebill_woo_save_product_safety_stock_field($product_id) {
    $val = isset($_POST['_diplebill_safety_stock']) ? sanitize_text_field($_POST['_diplebill_safety_stock']) : '';
    update_post_meta($product_id, '_diplebill_safety_stock', $val);
}

/**
 * Añadir campo de Stock de Seguridad en formulario de creación de Categoría
 */
function diplebill_woo_add_category_safety_stock_field() {
    ?>
    <div class="form-field">
        <label for="diplebill_cat_safety_stock"><?php _e('Margen de Seguridad DipleBill', 'diplebill-woo'); ?></label>
        <input type="number" min="0" name="diplebill_cat_safety_stock" id="diplebill_cat_safety_stock" />
        <p><?php _e('Margen de seguridad por defecto para los productos en esta categoría.', 'diplebill-woo'); ?></p>
    </div>
    <?php
}

/**
 * Añadir campo de Stock de Seguridad en formulario de edición de Categoría
 */
function diplebill_woo_edit_category_safety_stock_field($term) {
    $val = get_term_meta($term->term_id, 'diplebill_cat_safety_stock', true);
    ?>
    <tr class="form-field">
        <th scope="row" valign="top"><label for="diplebill_cat_safety_stock"><?php _e('Margen de Seguridad DipleBill', 'diplebill-woo'); ?></label></th>
        <td>
            <input type="number" min="0" name="diplebill_cat_safety_stock" id="diplebill_cat_safety_stock" value="<?php echo esc_attr($val); ?>" />
            <p class="description"><?php _e('Margen de seguridad por defecto para los productos en esta categoría.', 'diplebill-woo'); ?></p>
        </td>
    </tr>
    <?php
}

/**
 * Guardar stock de seguridad de Categoría
 */
function diplebill_woo_save_category_safety_stock_field($term_id) {
    if (isset($_POST['diplebill_cat_safety_stock'])) {
        update_term_meta($term_id, 'diplebill_cat_safety_stock', sanitize_text_field($_POST['diplebill_cat_safety_stock']));
    }
}

/**
 * Obtener el stock de seguridad aplicable a un producto
 */
function diplebill_woo_get_applicable_safety_stock($product) {
    $product_id = $product->get_id();

    // 1. Validar si el producto tiene un margen específico definido
    $product_stock = get_post_meta($product_id, '_diplebill_safety_stock', true);
    if ($product_stock !== '') {
        return intval($product_stock);
    }

    // 2. Validar si alguna de las categorías del producto tiene un margen definido
    $categories = $product->get_category_ids();
    if (!empty($categories)) {
        foreach ($categories as $cat_id) {
            $cat_stock = get_term_meta($cat_id, 'diplebill_cat_safety_stock', true);
            if ($cat_stock !== '') {
                return intval($cat_stock);
            }
        }
    }

    // 3. Retornar el margen global por defecto
    return intval(get_option('diplebill_safety_stock_default', 0));
}

/**
 * Interceptar y aplicar el stock de seguridad al leer stock del producto
 */
function diplebill_woo_apply_safety_stock($stock_qty, $product) {
    if (is_admin() && !wp_doing_ajax()) {
        return $stock_qty; // No alterar en el admin para que el comerciante vea el real
    }

    $margin = diplebill_woo_get_applicable_safety_stock($product);
    return max(0, $stock_qty - $margin);
}

/**
 * Interceptar el estado "en stock" o "agotado" basándose en el stock de seguridad calculado
 */
function diplebill_woo_apply_safety_stock_status($is_in_stock, $product) {
    if (is_admin() && !wp_doing_ajax()) {
        return $is_in_stock;
    }

    if ($product->managing_stock()) {
        $real_stock = $product->get_stock_quantity();
        $margin = diplebill_woo_get_applicable_safety_stock($product);
        if ($real_stock - $margin <= 0) {
            return false;
        }
    }

    return $is_in_stock;
}

/**
 * -------------------------------------------------------------
 * REGISTRO DE ORDEN (WOOCOMMERCE -> DIPLEBILL API)
 * -------------------------------------------------------------
 */

/**
 * Enviar orden de WooCommerce a la API de DipleBill al completar pago
 */
function diplebill_woo_sync_order_to_diplebill($order_id) {
    // Evitar re-envíos innecesarios
    if (get_post_meta($order_id, '_diplebill_invoice_number', true)) {
        return;
    }

    $order = wc_get_order($order_id);
    $api_url = diplebill_woo_get_api_url();
    $token = get_option('diplebill_api_token', '');
    $store_id = get_option('diplebill_store_id', '');
    $inventory_id = get_option('diplebill_inventory_id', '');

    if (empty($token) || empty($store_id)) {
        Log_diplebill_error("Falta configuración del conector DipleBill. Sincronización cancelada.");
        return;
    }

    $products = [];
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        if ($product) {
            $sku = $product->get_sku();
            
            // Si no tiene SKU, usar el ID de WooCommerce con prefijo
            if (empty($sku)) {
                $sku = 'WOO-ID-' . $product->get_id();
            }

            $products[] = [
                'sku'      => $sku,
                'name'     => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price'    => $item->get_subtotal() / $item->get_quantity(),
                'total'    => $item->get_subtotal(),
                'discount' => 0,
                'tax'      => $item->get_subtotal_tax(),
                'grand_total' => $item->get_total()
            ];
        }
    }

    // Sincronizar costos de envío como producto comodín
    $shipping_total = $order->get_shipping_total();
    if ($shipping_total > 0) {
        $products[] = [
            'sku'      => 'WOO-SHIPPING',
            'name'     => 'Envío / Despacho WooCommerce',
            'quantity' => 1,
            'price'    => $shipping_total,
            'total'    => $shipping_total,
            'discount' => 0,
            'tax'      => $order->get_shipping_tax(),
            'grand_total' => $shipping_total + $order->get_shipping_tax()
        ];
    }

    $mapped_method = diplebill_woo_map_payment_method($order->get_payment_method());
    $payment_metadata = [];
    if ($mapped_method === 'TRANSFER') {
        $payment_metadata = [
            'bank' => 'WooCommerce',
            'reference' => 'Pedido #' . $order->get_order_number()
        ];
    } elseif ($mapped_method === 'CARD') {
        $payment_metadata = [
            'card_last_four' => '0000',
            'reference' => $order->get_transaction_id() ? $order->get_transaction_id() : 'Pedido #' . $order->get_order_number()
        ];
    }

    // Registrar la factura en DipleBill
    $invoice_payload = [
        'store_id'         => $store_id,
        'inventory_id'     => $inventory_id,
        'client_name'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'invoice_date'     => date('Y-m-d H:i:s'),
        'invoice_note'     => 'Orden WooCommerce N° ' . $order->get_order_number(),
        'total'            => count($products),
        'discount'         => $order->get_discount_total(),
        'tax'              => $order->get_total_tax(),
        'grand_total'      => $order->get_total(),
        'payment_method'   => $mapped_method,
        'payment_date'     => date('Y-m-d H:i:s'),
        'payment_metadata' => $payment_metadata,
        'products'         => $products,
        'source'           => 'ECOMMERCE',
        'seller_id'        => null // Vendedor en blanco
    ];

    $response = wp_remote_post(rtrim($api_url, '/') . '/api/v1/invoices', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json'
        ],
        'body'    => json_encode($invoice_payload),
        'timeout' => 20
    ]);

    if (is_wp_error($response)) {
        Log_diplebill_error("Error enviando factura a DipleBill para Orden #{$order_id}: " . $response->get_error_message());
        $order->add_order_note("Error DipleBill: " . $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 201 || $code === 200) {
            $data = json_decode($body, true);
            $invoice_number = isset($data['data']['invoice_number']) ? $data['data']['invoice_number'] : ($data['invoice_number'] ?? '');

            if (!empty($invoice_number)) {
                update_post_meta($order_id, '_diplebill_invoice_number', $invoice_number);
                $order->add_order_note("Factura DipleBill registrada con éxito: #{$invoice_number}");
            }
        } else {
            Log_diplebill_error("DipleBill rechazó la factura para la Orden #{$order_id}. Código HTTP: {$code}. Body: " . $body);
            $order->add_order_note("Error DipleBill (HTTP {$code}): " . $body);
        }
    }
}

/**
 * Registrar logs de error internos en archivo debug log
 */
function Log_diplebill_error($msg) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[DipleBill WooCommerce Connector] " . $msg);
    }
}

/**
 * Mapear método de pago de WooCommerce a DipleBill
 */
function diplebill_woo_map_payment_method($method_id) {
    $method_id = strtolower($method_id);
    
    if ($method_id === 'bacs' || $method_id === 'cheque' || strpos($method_id, 'transfer') !== false) {
        return 'TRANSFER';
    }
    
    if ($method_id === 'stripe' || $method_id === 'paypal' || strpos($method_id, 'card') !== false || strpos($method_id, 'credit') !== false) {
        return 'CARD';
    }
    
    return 'CASH';
}

/**
 * Mostrar el número de factura en la administración del pedido
 */
function diplebill_woo_display_invoice_number_in_admin($order) {
    $invoice_number = get_post_meta($order->get_id(), '_diplebill_invoice_number', true);
    if (!empty($invoice_number)) {
        echo '<p><strong>' . esc_html__('Factura', 'diplebill-woo') . ':</strong> ' . esc_html($invoice_number) . '</p>';
    }
}
add_action('woocommerce_admin_order_data_after_billing_address', 'diplebill_woo_display_invoice_number_in_admin');
