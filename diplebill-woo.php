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
}
add_action('plugins_loaded', 'diplebill_woo_init');

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
    return 'https://api.diplebill.com';
}

/**
 * Renderizar la página de configuración
 */
function diplebill_woo_render_settings_page() {
    // Procesar envío del formulario
    if (isset($_POST['diplebill_save_settings']) && check_admin_referer('diplebill_woo_settings_nonce')) {
        update_option('diplebill_api_token', sanitize_text_field($_POST['diplebill_api_token']));
        update_option('diplebill_safety_stock_default', intval($_POST['diplebill_safety_stock_default']));
        
        if (isset($_POST['diplebill_store_id'])) {
            update_option('diplebill_store_id', sanitize_text_field($_POST['diplebill_store_id']));
        }
        if (isset($_POST['diplebill_inventory_id'])) {
            update_option('diplebill_inventory_id', sanitize_text_field($_POST['diplebill_inventory_id']));
        }

        echo '<div class="updated"><p>Configuración guardada correctamente.</p></div>';
    }

    // Procesar acción de conectar y cargar catálogos
    $connection_error = '';
    $connection_success = false;
    if (isset($_POST['diplebill_test_connection']) && check_admin_referer('diplebill_woo_settings_nonce')) {
        $api_url = diplebill_woo_get_api_url();
        $token = sanitize_text_field($_POST['diplebill_api_token']);

        if (!empty($token)) {
            // Obtener tiendas
            $stores_response = wp_remote_get($api_url . '/v1/stores', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json'
                ],
                'timeout' => 15
            ]);

            // Obtener inventarios
            $inventories_response = wp_remote_get($api_url . '/v1/inventories', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json'
                ],
                'timeout' => 15
            ]);

            if (is_wp_error($stores_response) || is_wp_error($inventories_response)) {
                $connection_error = 'Error al comunicarse con la API de DipleBill: ' . 
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
                    $connection_error = 'API de DipleBill retornó código HTTP: ' . $stores_code . '. Verifica tus credenciales.';
                }
            }
        } else {
            $connection_error = 'Debes especificar el Token antes de conectar.';
        }
    }

    $api_token = get_option('diplebill_api_token', '');
    $safety_stock_default = get_option('diplebill_safety_stock_default', '0');
    $selected_store = get_option('diplebill_store_id', '');
    $selected_inventory = get_option('diplebill_inventory_id', '');

    $stores = get_option('diplebill_stores_cache', []);
    $inventories = get_option('diplebill_inventories_cache', []);
    ?>
    <div class="wrap">
        <h1>DipleBill WooCommerce Connector</h1>

        <?php if (!empty($connection_error)) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($connection_error); ?></p></div>
        <?php endif; ?>

        <?php if ($connection_success) : ?>
            <div class="notice notice-success is-dismissible"><p>Conexión exitosa. Se han cargado las tiendas e inventarios de DipleBill.</p></div>
        <?php endif; ?>

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
        'payment_method'   => 'CASH', // Registrar venta de contado
        'payment_date'     => date('Y-m-d H:i:s'),
        'products'         => $products,
        'source'           => 'ECOMMERCE',
        'seller_id'        => null // Vendedor en blanco
    ];

    $response = wp_remote_post(rtrim($api_url, '/') . '/v1/invoices', [
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
