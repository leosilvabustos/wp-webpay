<?php
if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

/**
 * Plugin Name: WooCommerce Webpay Plus
 * Plugin URI: https://www.transbank.cl
 * Description: Recibe pagos en l&iacute;nea con Tarjetas de Cr&eacute;dito y Redcompra en tu WooCommerce a trav&eacute;s de Webpay Plus.
 * Version: 1.1
 * Author: Transbank
 * Author URI: https://www.transbank.cl
 */

add_action('plugins_loaded', 'woocommerce_transbank_init', 0);

define(WSWEBPAY_ROOT, dirname(__FILE__) . '/');

require_once( ABSPATH . "wp-includes/pluggable.php" );

function woocommerce_transbank_init() 
{

    require_once( dirname(__FILE__) . '/libwebpay/webpay-soap.php' );
    require_once( dirname(__FILE__) . '/libwebpay/class-logger.php' );

    if (!class_exists("WC_Payment_Gateway")){
        return;
    }

    class WC_Gateway_Transbank extends WC_Payment_Gateway
    {

        var $notify_url;

        /**
         * Constructor de gateway
         **/
        public function __construct()
        {

            $this->id = 'transbank';
            $this->icon = "https://www.transbank.cl/public/img/Logo_Webpay3-01-50x50.png";
            $this->method_title = __('Transbank – Pago a trav&eacute;s de Webpay Plus');
            $this->notify_url = add_query_arg('wc-api', 'WC_Gateway_' . $this->id, home_url('/'));
            $this->integration = include( 'integration/integration.php' );
            
            /**
             * Carga configuración y variables de inicio 
             **/
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');

            $this->config = array(
                "MODO" => $this->get_option('webpay_test_mode'),
                "PRIVATE_KEY" => $this->get_option('webpay_private_key'),
                "PUBLIC_CERT" => $this->get_option('webpay_public_cert'),
                "WEBPAY_CERT" => $this->get_option('webpay_webpay_cert'),
                "CODIGO_COMERCIO" => $this->get_option('webpay_commerce_code'),
                "URL_RETURN" => home_url('/') . '?wc-api=WC_Gateway_' . $this->id,
                "URL_FINAL" => "_URL_",
                "VENTA_DESC" => array(
                    "VD" => "Venta D&eacute;bito",
                    "VN" => "Venta Normal",
                    "VC" => "Venta en cuotas",
                    "SI" => "3 cuotas sin inter&eacute;s",
                    "S2" => "2 cuotas sin inter&eacute;s",
                    "NC" => "N cuotas sin inter&eacute;s",
                ),
            );

            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'check_ipn_response'));

            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }

        /**
         * Comprueba configuración de moneda (Peso Chileno)
         **/
        function is_valid_for_use()
        {
            if (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_' . $this->id . '_supported_currencies', array('CLP')))) {
                return false;
            }
            return true;
        }

        /**
         * Inicializar campos de formulario
         **/
        function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Activar/Desactivar', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Activar Transbank', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('T&iacute;tulo', 'woocommerce'),
                    'type' => 'text',
                    'default' => __('Pago con Tarjetas de Cr&eacute;dito o Redcompra', 'woocommerce'),
                    'disabled' => true
                ),
                'description' => array(
                    'title' => __('Descripci&oacute;n', 'woocommerce'),
                    'type' => 'textarea',
                    'disabled' => true,
                    'default' => __('Permite el pago de productos y/o servicios, con Tarjetas de Cr&eacute;dito y Redcompra a trav&eacute;s de Webpay Plus', 'woocommerce')
                ),
                'webpay_test_mode' => array(
                    'title' => __('Ambiente', 'woocommerce'),
                    'type' => 'select',
                    'options' => array('INTEGRACION' => 'Integraci&oacute;n', 'CERTIFICACION' => 'Certificaci&oacute;n', 'PRODUCCION' => 'Producci&oacute;n'),
                    'default'     => __( 'INTEGRACION', 'woocommerce' ),
                    'custom_attributes' => array(
                        'onchange' => "webpay_mode('".$this->integration[commerce_code]."', '".$this->integration[private_key]."', '".$this->integration[public_cert]."', '".$this->integration[webpay_cert]."')",
                    )
                ),
                'webpay_commerce_code' => array(
                    'title' => __('C&oacute;digo de Comercio', 'woocommerce'),
                    'type' => 'text',
                    'default' => __($this->integration[commerce_code], 'woocommerce'),
                ),
                'webpay_private_key' => array(
                    'title' => __('Llave Privada', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => __(str_replace("<br/>", "\n", $this->integration[private_key]), 'woocommerce'),
                    'css' => 'font-family: monospace',
                ),
                'webpay_public_cert' => array(
                    'title' => __('Certificado', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => __(str_replace("<br/>", "\n", $this->integration[public_cert]), 'woocommerce'),
                    'css' => 'font-family: monospace',
                ),
                'webpay_webpay_cert' => array(
                    'title' => __('Certificado Transbank', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => __(str_replace("<br/>", "\n", $this->integration[webpay_cert]), 'woocommerce'),
                    'css' => 'font-family: monospace',
                ),
            );
        }

        /**
         * Pagina Receptora
         **/
        function receipt_page($order)
        {
			Logger::debug('receipt_page: ');
			Logger::debug(Logger::dumpObject($order));
            echo $this->generate_transbank_payment($order);
        }

        /**
         * Obtiene respuesta IPN (Instant Payment Notification)
         **/
        function check_ipn_response()
        {
            @ob_clean();
            Logger::debug('check_ipn_response: ');
            if (isset($_POST)) {
                header('HTTP/1.1 200 OK');
                $this->check_ipn_request_is_valid($_POST);
            } else {
                echo "Ocurrio un error al procesar su Compra";
            }
        }

        /**
         * Valida respuesta IPN (Instant Payment Notification)
         **/
        public function check_ipn_request_is_valid($data)
        {
            Logger::debug('check_ipn_request_is_valid: ');
            $voucher = false;

            try {

                if (isset($data["token_ws"])) {
                    $token_ws = $data["token_ws"];
                } else {
                    $token_ws = 0;
                }

                $webpay = new WebPaySoap($this->config);
                $result = $webpay->webpayNormal->getTransactionResult($token_ws);
                
            } catch (Exception $e) {

                $result["error"] = "Error conectando a Webpay";
                $result["detail"] = $e->getMessage();
                
            }

            $order_info = new WC_Order($result->buyOrder);
            
            WC()->session->set($order_info->order_key, $result);

            if ($result->buyOrder && $order_info) {

                if (($result->VCI == "TSY" || $result->VCI == "") && $result->detailOutput->responseCode == 0) {

                    $voucher = true;
                    WC()->session->set($order_info->order_key . "_transaction_paid", 1);

                    WebPaySOAP::redirect($result->urlRedirection, array("token_ws" => $token_ws));

                    $order_info->add_order_note(__('Pago con WEBPAY PLUS', 'woocommerce'));
                    $order_info->update_status('completed');
                    $order_info->reduce_order_stock();
                    
                } else {

                    $responseDescription = htmlentities($result->detailOutput->responseDescription);
                }
            }

            if (!$voucher) {

                $date = new DateTime($result->transactionDate);
                
                WC()->session->set($order_info->order_key, "");

                $error_message = "Estimado cliente, le informamos que su orden".($result->buyOrder!=""&&$result->buyOrder!==null?" número ".$result->buyOrder:"" ). ", realizada el " . $date->format('d-m-Y H:i:s') . " termin&oacute; de forma inesperada " . ($responseDescription!=""?"(".$responseDescription.")":"");
                wc_add_notice(__('ERROR: ', 'woothemes') . $error_message, 'error');
				
				$error_message2 = "<ul><li>* Error en el ingreso de los datos de su tarjeta (fecha y/o código de seguridad).</li><li>* Su tarjeta no cuenta con saldo suficiente.</li><li>* Tarjeta aun no habilita en el sistema financiero.</li></ul>";
				wc_add_notice(__('El error se puede deber a: ', 'woothemes') . $error_message2, 'error');
				
                $redirectOrderReceived = $order_info->get_checkout_payment_url();
                WebPaySOAP::redirect($redirectOrderReceived, array("token_ws" => $token_ws));
            }

            die;
        }


        /**
         * Generar pago en Transbank
         **/
        function generate_transbank_payment($order_id)
        {

            Logger::debug('WC_Order: ' . $order_id);
            //$order_id = 54307;
            $order = new WC_Order($order_id);
			
			
			
			Logger::debug('WC_Order: ' . $order->status);



            $amount = (int) number_format($order->get_total(), 0, ',', '');

            $urlFinal = str_replace("_URL_", add_query_arg('key', $order->order_key, $order->get_checkout_order_received_url()), $this->config["URL_FINAL"]);
            
            try {

                $webpay = new WebPaySoap($this->config);

                $result = $webpay->webpayNormal->initTransaction($amount, $sessionId = "", $order_id, $urlFinal);

            } catch (Exception $e) {

                $result["error"] = "Error conectando a Webpay";
                $result["detail"] = $e->getMessage();
            }

            if (isset($result["token_ws"])) {

                $url = $result["url"];
                $token = $result["token_ws"];

                echo "<br/>Gracias por su pedido, por favor haga clic en el bot&oacute;n de abajo para pagar con WebPay.<br/><br/>";

                return '<form action="' . $url . '" method="post">' .
                        '<input type="hidden" name="token_ws" value="' . $token . '"></input>' .
                        '<input type="submit" value="WebPay"></input>' .
                        '</form>';
            } else {

                wc_add_notice(__('ERROR: ', 'woothemes') . 'Ocurri&oacute; un error al intentar conectar con WebPay Plus. Por favor intenta mas tarde.<br/>', 'error');
            }

        }

        /**
         * Procesar pago y retornar resultado
         **/
        function process_payment($order_id)
        {
            Logger::debug('process_payment... ');
            $order = new WC_Order($order_id);
            return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
        }

        /**
         * Opciones panel de administración
         **/
        public function admin_options()
        {
            ?>
            <h3><?php _e('Transbank', 'woocommerce'); ?></h3>
            <p><?php _e('Transbank es la empresa l&iacute;der en negocios de medio de pago seguros en Chile.'); ?></p>

            <?php if ($this->is_valid_for_use()) : ?>
                <?php if (empty($this->config["CODIGO_COMERCIO"])) : ?>
                    <div class="inline error">
                        <p><strong><?php _e('C&oacute;digo de Comercio', 'woocommerce'); ?></strong>: <?php _e('Para el correcto funcionamiento de Webpay Plus debes ingresar tu C&oacute;digo de Comercio', 'woocommerce'); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (empty($this->config["PRIVATE_KEY"])) : ?>
                    <div class="inline error">
                        <p><strong><?php _e('Llave Privada', 'woocommerce'); ?></strong>: <?php _e('Para el correcto funcionamiento de Webpay Plus debes ingresar tu Llave Privada', 'woocommerce'); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (empty($this->config["PUBLIC_CERT"])) : ?>
                    <div class="inline error">
                        <p><strong><?php _e('Certificado', 'woocommerce'); ?></strong>: <?php _e('Para el correcto funcionamiento de Webpay Plus debes ingresar tu Certificado', 'woocommerce'); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (empty($this->config["WEBPAY_CERT"])) : ?>
                    <div class="inline error">
                        <p><strong><?php _e('Certificado Transbank', 'woocommerce'); ?></strong>: <?php _e('Para el correcto funcionamiento de Webpay Plus debes ingresar tu Certificado Transbank', 'woocommerce'); ?></p>
                    </div>
                <?php endif; ?>

                <table class="form-table">
                <?php
                $this->generate_settings_html();
                ?>
                </table>

            <?php else : ?>
                <div class="inline error">
                    <p>
                        <strong><?php _e('Webpay Plus ', 'woocommerce');
                ?></strong>: <?php _e('Este Plugin est&aacute; dise&ntilde;ado para operar con Webpay Plus solo en Pesos Chilenos.', 'woocommerce');
                ?>
                    </p>
                </div>
            <?php
            endif;
        }

    }

    /**
     * Añadir Transbank Plus a Woocommerce
     **/
    function woocommerce_add_transbank_gateway($methods) 
    {
        $methods[] = 'WC_Gateway_transbank';
        return $methods;
    }

    /**
     * Muestra detalle de pago a Cliente a finalizar compra
     **/
    function pay_content($order_id)
    {
        $order_info = new WC_Order($order_id);
        $transbank_data = new WC_Gateway_transbank;

        if ($order_info->payment_method_title == $transbank_data->title) {
            
            if (WC()->session->get($order_info->order_key . "_transaction_paid") == "" && WC()->session->get($order_info->order_key) == "") {

                wc_add_notice(__('Compra <strong>Anulada</strong>', 'woocommerce') . ' por usuario. Recuerda que puedes pagar o 
                    cancelar tu compra cuando lo desees desde <a style="font-weight:bold;" href="' . wc_get_page_permalink('myaccount') . '">' . __('Tu Cuenta', 'woocommerce') . '</a>', 'error');
                wp_redirect($order_info->get_checkout_payment_url());

                die;
            }
            
        } else {
            return;
        }

        $finalResponse = WC()->session->get($order_info->order_key);
        WC()->session->set($order_info->order_key, "");

        $paymentTypeCode = $finalResponse->detailOutput->paymentTypeCode;
        $paymenCodeResult = $transbank_data->config['VENTA_DESC'][$paymentTypeCode];

        if ($finalResponse->detailOutput->responseCode == 0) {
            $transactionResponse = "Aceptado";
        } else {
            $transactionResponse = "Rechazado [" . $finalResponse->detailOutput->responseCode . "]";
        }

        $date_accepted = new DateTime($finalResponse->transactionDate);

        if ($finalResponse != null) {

            echo '</br><h2>Detalles del pago</h2>' .
            '<table class="shop_table order_details">' .
            '<tfoot>' .
            '<tr>' .
            '<th scope="row">Respuesta de la Transacci&oacute;n:</th>' .
            '<td><span class="RT">' . $transactionResponse . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Orden de Compra:</th>' .
            '<td><span class="RT">' . $finalResponse->buyOrder . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Codigo de Autorizaci&oacute;n:</th>' .
            '<td><span class="CA">' . $finalResponse->detailOutput->authorizationCode . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Fecha Transacci&oacute;n:</th>' .
            '<td><span class="FC">' . $date_accepted->format('d-m-Y') . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row"> Hora Transacci&oacute;n:</th>' .
            '<td><span class="FT">' . $date_accepted->format('H:i:s') . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Tarjeta de Cr&eacute;dito:</th>' .
            '<td><span class="TC">************' . $finalResponse->cardDetail->cardNumber . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Tipo de Pago:</th>' .
            '<td><span class="TP">' . $paymenCodeResult . '</span></td>' .
            '</tr>' .
            /*'<tr>' .
            '<th scope="row">Tipo de Cuotas:</th>' .
            '<td><span class="TP"></span></td>' .
            '</tr>' .*/
            '<tr>' .
            '<th scope="row">Monto Compra:</th>' .
            '<td><span class="amount">' . $finalResponse->detailOutput->amount . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">N&uacute;mero de Cuotas:</th>' .
            '<td><span class="NC">' . $finalResponse->detailOutput->sharesNumber . '</span></td>' .
            '</tr>' .
            '</tfoot>' .
            '</table><br/>';
        }
    }

    add_action('woocommerce_thankyou', 'pay_content', 1);
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_transbank_gateway');
}

if (strpos($_SERVER['REQUEST_URI'], "wc_gateway_transbank") && is_user_logged_in()){
    ?>
        <script src="../wp-content/plugins/woocommerce-transbank/integration/js/integration.js"></script>          
    <?php
}
