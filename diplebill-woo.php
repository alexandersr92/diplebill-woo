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
 * Verificar si WooCommerce está activo
 */
function diplebill_woo_check_dependencies() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'diplebill_woo_missing_wc_notice');
        return false;
    }
    return true;
}

/**
 * Mostrar aviso si WooCommerce no está activo
 */
function diplebill_woo_missing_wc_notice() {
    echo '<div class="error"><p>';
    echo esc_html__('El plugin DipleBill WooCommerce Connector requiere que WooCommerce esté instalado y activo.', 'diplebill-woo');
    echo '</p></div>';
}

/**
 * Inicializar el plugin
 */
function diplebill_woo_init() {
    if (!diplebill_woo_check_dependencies()) {
        return;
    }

    // Agregar menú de configuración dentro de WooCommerce
    add_action('admin_menu', 'diplebill_woo_add_settings_menu');
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
 * Renderizar la página de configuración
 */
function diplebill_woo_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>DipleBill WooCommerce Connector</h1>
        <div class="notice notice-success is-dismissible" style="margin-left: 0; margin-top: 15px;">
            <p><strong>¡Hola Mundo!</strong> El conector de DipleBill con WooCommerce ha sido activado y está listo para configurarse.</p>
        </div>
        <table class="form-table" style="margin-top: 20px;">
            <tr valign="top">
                <th scope="row">Estado de la Conexión</th>
                <td>
                    <span class="dashicons dashicons-yes text-success" style="color: #46b450; font-size: 30px; width: 30px; height: 30px;"></span>
                    <span style="font-weight: bold; vertical-align: super;">Plugin Activo (Modo Demo)</span>
                </td>
            </tr>
        </table>
    </div>
    <?php
}
