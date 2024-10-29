<?php
/**
Plugin Name: Baghera
Description: Emetti fatture elettroniche con Baghera
Version:     0.1
Requires PHP: 7.0
Author:      GlueLabs
Author URI:  https://www.glue-labs.com
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: woocommerce-baghera
Domain Path: /languages

@package woocommerce_baghera
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly .
}

/**
 * WoocommerceBaghera
 */
class Woocommerce_Baghera {

	/**
	 * Options Variable
	 *
	 * @var array
	 */
	private $woocommerce_baghera_options;

	/**
	 * Debug Variable
	 *
	 * @var boolean
	 */
	private $debug = false;

	/**
	 * __construct
	 *
	 * @return void
	 */
	public function __construct() {

		// Procedura aggiunta nuovi field: add_settings_field + sanitize + callback .
		add_action( 'admin_menu', array( $this, 'woocommerce_baghera_add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'woocommerce_baghera_page_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'baghera_options_page_script' ) );

		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'custom_orders_list_column_content' ), 20, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'custom_shop_order_column' ) );

		add_action( 'woocommerce_payment_complete', array( $this, 'woocommerce_baghera_completed_payment' ) );
		add_filter( 'woocommerce_order_status_completed', array( $this, 'woocommerce_baghera_completed_payment' ) );
		add_action( 'woocommerce_new_order', array( $this, 'woocommerce_baghera_completed_payment' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_shop_order_meta_box' ) );

		register_activation_hook( __FILE__, array( $this, 'swoocommerce_baghera_addon_activate' ) );

	}

	/**
	 * Plugin Activation - Check Dipendenze .
	 *
	 * @return void
	 */
	public function some_woocommerce_baghera_addon_activate() {

		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Please install and Activate WooCommerce.', 'woocommerce-addon-slug' ), 'Plugin dependency check', array( 'back_link' => true ) );
		}
	}


	/**
	 * Nuova fattura su nuovo ordine, in modo automatizzato
	 *
	 * @param int $order_id .
	 */
	public function woocommerce_baghera_completed_payment( $order_id ) {

		$woocommerce_baghera_options = get_option( 'woocommerce_baghera_option_name' );

		if ( 'fattura_automatica' === $woocommerce_baghera_options['fattura_automatica'] ) {
			$this->creazione_fattura( $order_id, true );
		}
	}

	/**
	 * WooCommerceBaghera - baghera_options_page_script
	 *
	 * @return void
	 */
	public function baghera_options_page_script() {

		// JS .
		wp_enqueue_script( 'baghera-options-page-js', plugins_url( 'js/woocommerce-baghera.js', __FILE__ ), array(), null, true );
		$woocommerce_baghera_options = get_option( 'woocommerce_baghera_option_name' );
		$script_params               = array(
			'api_key' => $woocommerce_baghera_options['api_key'],
		);
		wp_localize_script( 'baghera-options-page-js', 'scriptParams', $script_params );

		// CSS .
		wp_enqueue_style( 'baghera-options-page-css', plugins_url( 'css/woocommerce-baghera.css', __FILE__ ) );

	}


	/**
	 * Add New colum with title .
	 *
	 * @param  mixed $columns .
	 * @return mixed $reordered_columns .
	 */
	public function custom_shop_order_column( $columns ) {
		$reordered_columns = array();

		// Inserting columns to a specific location .
		foreach ( $columns as $key => $column ) {
			$reordered_columns[ $key ] = $column;
			if ( 'order_status' === $key ) {
				// Inserting after "Status" column .
				$reordered_columns['fatturazione_elettronica'] = __( 'Fatturazione Elettronica', 'theme_domain' );
			}
		}
		return $reordered_columns;
	}


	/**
	 * Callback - Delete metadato fattura creata
	 *
	 * @param int $order_id .
	 *
	 * @return void
	 */
	public function delete_metadati_fattura_creata( $order_id = null ) {

		if ( ! $order_id ) {
			if ( isset( $_REQUEST['order_id'] ) ) {
				$order_id = wp_unslash( intval( $_REQUEST['order_id'] ) );
			}
		}

		delete_post_meta( $order_id, 'fattura_creata' );
		delete_post_meta( $order_id, 'fattura_status' );

		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			header( 'Location: ' . esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
		}

	}

	/**
	 * Callback - Fattura Status
	 *
	 * @param int $fattura_id .
	 *
	 * @return string $fattura_status
	 */
	public function get_fattura_status( $fattura_id ) {

		$fattura_id = intval( $fattura_id );

		$woocommerce_baghera_options = get_option( 'woocommerce_baghera_option_name' );

		$args = array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'API-KEY'      => $woocommerce_baghera_options['api_key'],
			),
			'timeout' => 30,
		);

		$fattura = wp_remote_get( 'https://apikey-dot-baghere-suite.appspot.com/api_fattura/' . $fattura_id, $args );

		$fattura_body = json_decode( $fattura['body'] );

		$fattura_status = $fattura_body->stato;

		return $fattura_status;

	}


	/**
	 * Adding custom fields meta data for each new column .
	 *
	 * @param  mixed $column .
	 * @param  mixed $post_id .
	 * @return void
	 */
	public function custom_orders_list_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'fatturazione_elettronica':
				if ( get_post_meta( $post_id, 'fattura_creata', true ) ) {
					$fattura_id     = get_post_meta( $post_id, 'fattura_creata', true );
					$fattura_status = get_post_meta( $post_id, 'fattura_status', true );

					if ( 'mancata_consegna' !== $fattura_status ) {
						$fattura_status = $this->get_fattura_status( $fattura_id );
						update_post_meta( $post_id, 'fattura_status', $fattura_status );
					}

					switch ( $fattura_status ) {
						case 'draft_error':
							$fattura_status_class = 'red';
							break;
						case 'mancata_consegna':
							$fattura_status_class = 'green';
							break;
						default:
							$fattura_status_class = 'blue';
							break;
					}
					$detail_url         = 'https://baghera.it/user/finance/' . intval( $fattura_id ) . '/detail';
					$delete_locally_url = '/wp-admin/admin.(php?page=woocommerce-baghera-delete-metadato-fattura-creata&order_id=' . intval( $post_id );
					echo wp_kses_post( '<a href="' . esc_url( $detail_url ) . '">Consulta fattura</a>' );
					echo wp_kses_post( '<br>' );
					echo wp_kses_post( '<b>Status:</b> <span class="' . esc_html( $fattura_status_class ) . '">' . esc_html( $fattura_status ) . '</span>' );
					echo wp_kses_post( '<br>' );
					echo wp_kses_post( '(<a href="' . esc_url( $delete_locally_url ) . '"><small>Annulla Fattura su database locale</small></a>)' );
				} else {
					$order        = wc_get_order( $post_id );
					$order_status = $order->get_status();
					if ( 'completed' === $order_status ) {
						$bare_url     = '/wp-admin/admin.php?page=woocommerce-baghera-creazione-fattura&order_id=' . intval( $post_id );
						$complete_url = wp_nonce_url( $bare_url, 'crea_fattura' );
						echo wp_kses_post( '<a href="' . esc_url( $complete_url ) . '">Crea Fattura</a>' );
					}
				}
				break;
		}
	}


	/**
	 * Shop Order Meta Box - Annulla Fattura .
	 *
	 * @return void
	 */
	public function add_shop_order_meta_box() {
		add_meta_box(
			'annulla_record_fattura',
			__( 'Annulla Fattura', 'woocommerce-baghera' ),
			array( $this, 'shop_order_display_callback_annulla_fattura_locale' ),
			'shop_order'
		);

	}

	/**
	 * WoocommerceBaghera add plugin page
	 *
	 * @return void
	 */
	public function woocommerce_baghera_add_plugin_page() {
		add_menu_page(
			'WooCommerce Baghera', // page_title .
			'WooCommerce Baghera', // menu_title .
			'manage_options', // capability .
			'woocommerce-baghera', // menu_slug .
			array( $this, 'create_admin_page' ), // callback function .
			'dashicons-money-alt'
		); // icon_url .

		// Hidden .
		add_submenu_page(
			null, // parent slug - List on https://developer.wordpress.org/reference/functions/add_submenu_page .
			'Creazione Fattura', // title tag, when menu is selected .
			'Creazione Fattura', // sub-menu displayed name .
			'edit_theme_options', // capability required for the user to see it - List on https://codex.wordpress.org/Roles_and_Capabilities#Capabilities .
			'woocommerce-baghera-creazione-fattura', // slug name for this menu, unique .
			array( $this, 'creazione_fattura' ) // callback function .
		);

		// Hidden .
		add_submenu_page(
			null, // parent slug - List on https://developer.wordpress.org/reference/functions/add_submenu_page .
			'Rimuovi Metadato di Fattura Creata', // title tag, when menu is selected .
			'Rimuovi Metadato di Fattura Creata', // sub-menu displayed name .
			'edit_theme_options', // capability required for the user to see it - List on https://codex.wordpress.org/Roles_and_Capabilities#Capabilities .
			'woocommerce-baghera-delete-metadato-fattura-creata', // slug name for this menu, unique .
			array( $this, 'delete_metadati_fattura_creata' ) // callback function .
		);

	}

	/**
	 * WoocommerceBaghera create admin page
	 *
	 * @return void
	 */
	public function create_admin_page() {
		$this->woocommerce_baghera_options = get_option( 'woocommerce_baghera_option_name' ); ?>

		<div class="wrap">
			<h2>WooCommerce Baghera - Fatturazione Elettronica</h2>
			<p></p>
							<?php settings_errors(); ?>

			<form method="post" action="options.php">
								<?php
								settings_fields( 'woocommerce_baghera_option_group' );
								do_settings_sections( 'woocommerce-baghera-admin' );
								submit_button();
								?>
			</form>
		</div>
						<?php
	}

	/**
	 * WoocommerceBaghera page_init
	 *
	 * @return void
	 */
	public function woocommerce_baghera_page_init() {
		register_setting(
			'woocommerce_baghera_option_group', // option_group .
			'woocommerce_baghera_option', // option_name .
			array( $this, 'woocommerce_baghera_sanitize' ) // sanitize_callback .
		);

		/* Sezione Header */
		add_settings_section(
			'woocommerce_baghera_header_section', // id .
			'Benvenuti in Baghera!', // title .
			array( $this, 'woocommerce_baghera_header_info' ), // callback .
			'woocommerce-baghera-admin' // page .
		);

		add_settings_field(
			'api_key', // id .
			'API Key', // title .
			array( $this, 'api_key_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_header_section' // section .
		);

		add_settings_field(
			'fattura_automatica', // id .
			'Fattura automatica', // title .
			array( $this, 'fattura_automatica_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_header_section' // section .
		);

		/* Sezione Dati Venditore */

		add_settings_section(
			'woocommerce_baghera_dati_venditore_section', // id .
			'Dati Venditore', // title .
			array( $this, 'woocommerce_baghera_dati_venditore_info' ), // callback .
			'woocommerce-baghera-admin' // page .
		);

		add_settings_field(
			'denominazione_venditore', // id .
			'Denominazione Azienda', // title .
			array( $this, 'denominazione_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_dati_venditore_section' // section .
		);

		add_settings_field(
			'partita_iva_venditore', // id .
			'Partita IVA', // title .
			array( $this, 'partita_iva_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_dati_venditore_section' // section .
		);

		add_settings_field(
			'indirizzo_venditore', // id .
			'Indirizzo', // title .
			array( $this, 'indirizzo_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_dati_venditore_section' // section .
		);

		add_settings_field(
			'cap_venditore', // id .
			'CAP', // title .
			array( $this, 'cap_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_dati_venditore_section' // section .
		);

		add_settings_field(
			'comune_venditore', // id .
			'Comune', // title .
			array( $this, 'comune_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_dati_venditore_section' // section .
		);

		add_settings_field(
			'provincia_codice_due_caratteri_venditore', // id .
			'Provincia (codice due caratteri)', // title .
			array( $this, 'provincia_codice_due_caratteri_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_dati_venditore_section' // section .
		);

		add_settings_field(
			'nazione_codice_due_caratteri_venditore', // id .
			'Nazione (codice due caratteri)', // title .
			array( $this, 'nazione_codice_due_caratteri_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_dati_venditore_section' // section .
		);

		add_settings_field(
			'e_mail_venditore', // id .
			'E-mail aziendale', // title .
			array( $this, 'e_mail_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_dati_venditore_section' // section .
		);

		add_settings_field(
			'socio_unico', // id .
			'Socio Unico', // title .
			array( $this, 'socio_unico_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_dati_venditore_section' // section .
		);

		add_settings_field(
			'stato_liquidazione', // id .
			'Stato Liquidazione', // title .
			array( $this, 'stato_liquidazione_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_dati_venditore_section' // section .
		);

		add_settings_section(
			'woocommerce_baghera_rea_section', // id .
			'Dati REA', // title .
			array( $this, 'woocommerce_baghera_rea_info' ), // callback .
			'woocommerce-baghera-admin' // page .
		);

		add_settings_field(
			'ufficio_rea_provincia_codice_due_caratteri_venditore', // id .
			'Ufficio Rea - Provincia (codice due caratteri)', // title .
			array( $this, 'ufficio_rea_provincia_codice_due_caratteri_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_rea_section' // section .
		);

		add_settings_field(
			'numero_rea_venditore', // id .
			'Numero Rea', // title .
			array( $this, 'numero_rea_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_rea_section' // section .
		);

		add_settings_field(
			'capitale_sociale_venditore', // id .
			'Capitale sociale (punto come separatore)', // title .
			array( $this, 'capitale_sociale_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_rea_section' // section .
		);

		add_settings_section(
			'woocommerce_baghera_pagamenti_section', // id .
			'Dati Pagamenti', // title .
			array( $this, 'woocommerce_baghera_pagamenti_info' ), // callback .
			'woocommerce-baghera-admin' // page .
		);

		add_settings_field(
			'iban_venditore', // id .
			'IBAN conto aziendale', // title .
			array( $this, 'iban_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_pagamenti_section' // section .
		);

		add_settings_field(
			'nome_istituto_finanziario', // id .
			'Nome Istituto Finanziario', // title .
			array( $this, 'nome_istituto_finanziario_venditore_callback' ), // callback .
			'woocommerce-baghera-admin', // page .
			'woocommerce_baghera_pagamenti_section' // section .
		);
	}

	/**
	 * WoocommerceBaghera sanitize
	 *
	 * @param  mixed $input .
	 * @return mixed $sanitary_values .
	 */
	public function woocommerce_baghera_sanitize( $input ) {
		$sanitary_values = array();

		/* Tipi TextArea */

		if ( isset( $input['partita_iva_venditore'] ) ) {
			$sanitary_values['partita_iva_venditore'] = sanitize_text_field( $input['partita_iva_venditore'] );
		}

		if ( isset( $input['indirizzo_venditore'] ) ) {
			$sanitary_values['indirizzo_venditore'] = sanitize_text_field( $input['indirizzo_venditore'] );
		}

		if ( isset( $input['cap_venditore'] ) ) {
			$sanitary_values['cap_venditore'] = sanitize_text_field( $input['cap_venditore'] );
		}

		if ( isset( $input['comune_venditore'] ) ) {
			$sanitary_values['comune_venditore'] = sanitize_text_field( $input['comune_venditore'] );
		}

		if ( isset( $input['provincia_codice_due_caratteri_venditore'] ) ) {
			$sanitary_values['provincia_codice_due_caratteri_venditore'] = sanitize_text_field( $input['provincia_codice_due_caratteri_venditore'] );
		}

		if ( isset( $input['nazione_codice_due_caratteri_venditore'] ) ) {
			$sanitary_values['nazione_codice_due_caratteri_venditore'] = sanitize_text_field( $input['nazione_codice_due_caratteri_venditore'] );
		}

		if ( isset( $input['e_mail_venditore'] ) ) {
			$sanitary_values['e_mail_venditore'] = sanitize_text_field( $input['e_mail_venditore'] );
		}

		if ( isset( $input['ufficio_rea_provincia_codice_due_caratteri_venditore'] ) ) {
			$sanitary_values['ufficio_rea_provincia_codice_due_caratteri_venditore'] = sanitize_text_field( $input['ufficio_rea_provincia_codice_due_caratteri_venditore'] );
		}

		if ( isset( $input['numero_rea_venditore'] ) ) {
			$sanitary_values['numero_rea_venditore'] = sanitize_text_field( $input['numero_rea_venditore'] );
		}

		if ( isset( $input['capitale_sociale_venditore'] ) ) {
			$sanitary_values['capitale_sociale_venditore'] = sanitize_text_field( $input['capitale_sociale_venditore'] );
		}

		if ( isset( $input['stato_liquidazione_venditore'] ) ) {
			$sanitary_values['stato_liquidazione_venditore'] = sanitize_text_field( $input['stato_liquidazione_venditore'] );
		}

		if ( isset( $input['iban_venditore'] ) ) {
			$sanitary_values['iban_venditore'] = sanitize_text_field( $input['iban_venditore'] );
		}

		if ( isset( $input['nome_istituto_finanziario_venditore'] ) ) {
			$sanitary_values['nome_istituto_finanziario_venditore'] = sanitize_text_field( $input['nome_istituto_finanziario_venditore'] );
		}

		if ( isset( $input['denominazione_venditore'] ) ) {
			$sanitary_values['denominazione_venditore'] = sanitize_text_field( $input['denominazione_venditore'] );
		}

		if ( isset( $input['api_key'] ) ) {
			$sanitary_values['api_key'] = sanitize_text_field( $input['api_key'] );
		}

		/* Tipi Select o Checkbox*/

		if ( isset( $input['socio_unico'] ) ) {
			$sanitary_values['socio_unico'] = $input['socio_unico'];
		}

		if ( isset( $input['stato_liquidazione'] ) ) {
			$sanitary_values['stato_liquidazione'] = $input['stato_liquidazione'];
		}

		if ( isset( $input['fattura_automatica'] ) ) {
			$sanitary_values['fattura_automatica'] = $input['fattura_automatica'];
		}

		return $sanitary_values;
	}

	/**
	 * Callback - partita_iva_venditore_callback
	 *
	 * @return void
	 */
	public function partita_iva_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[partita_iva_venditore]" id="partita_iva_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['partita_iva_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['partita_iva_venditore'] ) : ''
		);
	}

	/**
	 * WoocommerceBaghera section_info
	 *
	 * @return void
	 */
	public function woocommerce_baghera_header_info() {
		$logo = plugin_dir_url( __FILE__ ) . '/img/baghera_seat.png';
		printf( '<img width="250" src="' . esc_html( $logo ) . '">' );
	}

	/**
	 * Callback - indirizzo_venditore_callback
	 *
	 * @return void
	 */
	public function indirizzo_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[indirizzo_venditore]" id="indirizzo_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['indirizzo_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['indirizzo_venditore'] ) : ''
		);
	}

	/**
	 * Callback - cap_venditore_callback
	 *
	 * @return void
	 */
	public function cap_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[cap_venditore]" id="cap_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['cap_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['cap_venditore'] ) : ''
		);
	}

	/**
	 * Callback - comune_venditore_callback
	 *
	 * @return void
	 */
	public function comune_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[comune_venditore]" id="comune_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['comune_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['comune_venditore'] ) : ''
		);
	}

	/**
	 * Callback - provincia_codice_due_caratteri_venditore_callback
	 *
	 * @return void
	 */
	public function provincia_codice_due_caratteri_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[provincia_codice_due_caratteri_venditore]" id="provincia_codice_due_caratteri_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['provincia_codice_due_caratteri_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['provincia_codice_due_caratteri_venditore'] ) : ''
		);
	}

	/**
	 * Callback - nazione_codice_due_caratteri_venditore_callback
	 *
	 * @return void
	 */
	public function nazione_codice_due_caratteri_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[nazione_codice_due_caratteri_venditore]" id="nazione_codice_due_caratteri_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['nazione_codice_due_caratteri_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['nazione_codice_due_caratteri_venditore'] ) : ''
		);
	}

	/**
	 * Callback - e_mail_venditore_callback
	 *
	 * @return void
	 */
	public function e_mail_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[e_mail_venditore]" id="e_mail_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['e_mail_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['e_mail_venditore'] ) : ''
		);
	}

	/**
	 * Callback - ufficio_rea_provincia_codice_due_caratteri_venditore_callback
	 *
	 * @return void
	 */
	public function ufficio_rea_provincia_codice_due_caratteri_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[ufficio_rea_provincia_codice_due_caratteri_venditore]" id="ufficio_rea_provincia_codice_due_caratteri_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['ufficio_rea_provincia_codice_due_caratteri_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['ufficio_rea_provincia_codice_due_caratteri_venditore'] ) : ''
		);
	}

	/**
	 * Callback - numero_rea_venditore_callback
	 *
	 * @return void
	 */
	public function numero_rea_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[numero_rea_venditore]" id="numero_rea_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['numero_rea_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['numero_rea_venditore'] ) : ''
		);
	}

	/**
	 * Callback - capitale_sociale_venditore_callback
	 *
	 * @return void
	 */
	public function capitale_sociale_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[capitale_sociale_venditore]" id="capitale_sociale_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['capitale_sociale_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['capitale_sociale_venditore'] ) : ''
		);
	}

	/**
	 * Callback - stato_liquidazione_venditore_callback
	 *
	 * @return void
	 */
	public function stato_liquidazione_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[stato_liquidazione_venditore]" id="stato_liquidazione_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['stato_liquidazione_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['stato_liquidazione_venditore'] ) : ''
		);
	}

	/**
	 * Callback - iban_venditore_callback
	 *
	 * @return void
	 */
	public function iban_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[iban_venditore]" id="iban_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['iban_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['iban_venditore'] ) : ''
		);
	}

	/**
	 * Callback - nome_istituto_finanziario_venditore_callback
	 *
	 * @return void
	 */
	public function nome_istituto_finanziario_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[nome_istituto_finanziario_venditore]" id="nome_istituto_finanziario_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['nome_istituto_finanziario_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['nome_istituto_finanziario_venditore'] ) : ''
		);
	}


	/**
	 * Callback - denominazione_venditore_callback
	 *
	 * @return void
	 */
	public function denominazione_venditore_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[denominazione_venditore]" id="denominazione_venditore" value="%s">',
			isset( $this->woocommerce_baghera_options['denominazione_venditore'] ) ? esc_attr( $this->woocommerce_baghera_options['denominazione_venditore'] ) : ''
		);
	}

	/**
	 * Callback - api_key_callback
	 *
	 * @return void
	 */
	public function api_key_callback() {
		printf(
			'<input required class="regular-text" type="text" name="woocommerce_baghera_option[api_key]" id="api_key" value="%s">',
			isset( $this->woocommerce_baghera_options['api_key'] ) ? esc_attr( $this->woocommerce_baghera_options['api_key'] ) : ''
		);

		printf( '<button id="baghera-api-connection-button"type="button" class="btn btn-primary">Test Connessione</button>' );
		printf( '<output id="baghera-api-connection-output" style="margin: 1rem;"></output>' );

	}

	/**
	 * Callback - socio_unico_callback
	 *
	 * @return void
	 */
	public function socio_unico_callback() {
		?>
		<select name="woocommerce_baghera_option[socio_unico]" id="socio_unico">
		<?php $selected = ( isset( $this->woocommerce_baghera_options['socio_unico'] ) && 'SU' === $this->woocommerce_baghera_options['socio_unico'] ) ? 'selected' : ''; ?>
			<option value="SU" <?php echo esc_html( $selected ); ?>>SU (Socio Unico)</option>
		<?php $selected = ( isset( $this->woocommerce_baghera_options['socio_unico'] ) && 'SM' === $this->woocommerce_baghera_options['socio_unico'] ) ? 'selected' : ''; ?>
			<option value="SM" <?php echo esc_html( $selected ); ?>>SM (Soci Multipli)</option>
		</select>
		<?php
	}

	/**
	 * Callback - stato_liquidazione_callback
	 *
	 * @return void
	 */
	public function stato_liquidazione_callback() {
		?>
		<select name="woocommerce_baghera_option[stato_liquidazione]" id="stato_liquidazione">
		<?php $selected = ( isset( $this->woocommerce_baghera_options['stato_liquidazione'] ) && 'LN' === $this->woocommerce_baghera_options['stato_liquidazione'] ) ? 'selected' : ''; ?>
			<option value="LN" <?php echo esc_html( $selected ); ?>>LN (Società non in liquidazione)</option>
		<?php $selected = ( isset( $this->woocommerce_baghera_options['stato_liquidazione'] ) && 'LS' === $this->woocommerce_baghera_options['stato_liquidazione'] ) ? 'selected' : ''; ?>
			<option value="LS" <?php echo esc_html( $selected ); ?>>LS (Società in liquidazione)</option>
		</select>
		<?php
	}

	/**
	 * Callback - fattura_automatica_callback
	 *
	 * @return void
	 */
	public function fattura_automatica_callback() {
		printf(
			'<input type="checkbox" name="woocommerce_baghera_option[fattura_automatica]" id="fattura_automatica" value="fattura_automatica" %s> <label for="fattura_automatica">Crea fattura su ordine completato</label>',
			( isset( $this->woocommerce_baghera_options['fattura_automatica'] ) && 'fattura_automatica' === $this->woocommerce_baghera_options['fattura_automatica'] ) ? 'checked' : ''
		);
	}

	/**
	 * Callback - create_fattura
	 *
	 * @param int     $order_id .
	 * @param boolean $internal_call La chiamata viene da hook? .
	 *
	 * @return void
	 */
	public function creazione_fattura( $order_id = null, $internal_call = false ) {

		if ( isset( $_REQUEST['_wpnonce'] ) ) {
			$retrieved_nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
			if ( ! wp_verify_nonce( $retrieved_nonce, 'crea_fattura' ) ) {
				die( 'Failed security check' );
			}
		} else {
			die( 'Failed security check' );
		}

		if ( ! $order_id ) {
			if ( isset( $_REQUEST['order_id'] ) ) {
				$order_id = wp_unslash( intval( $_REQUEST['order_id'] ) );
			}
		}

		if ( ! get_post_meta( $order_id, 'fattura_creata' ) ) {

			$selected_order = wc_get_order( $order_id );

			if ( $selected_order ) {

				/* Codice destinatario */
				if ( get_post_meta( $id_ordine_scelto, '_billing_piva', true ) ) {
					$codice_destinatario = get_post_meta( $id_ordine_scelto, '_billing_piva', true );
				} else {
					$codice_destinatario = '0000000';
				}

				/* Get All Plugin Options */
				$woocommerce_baghera_options = get_option( 'woocommerce_baghera_option_name' );

				/* Dettaglio Linee, Dati Riepilogo, Cart */
				$dettaglio_linee = array();
				$dati_riepilogo  = array();
				$cart            = array();

				$counter = 1;

				$tax_rates = array();
				foreach ( $selected_order->get_items( 'tax' ) as $item_id => $item ) {
										/* Tax Rate */
										$tax_rate_id = $item->get_rate_id(); // Tax rate ID .
										$tax_percent = WC_Tax::get_rate_percent( $tax_rate_id ); // Tax percentage .
										$tax_rate    = number_format( str_replace( '%', '', $tax_percent ), 2 ); // Tax rate .
										array_push( $tax_rates, $tax_rate );
				}

				$aliquote                = array();
				$imponibile_per_aliquota = array();
				$imposta_per_aliquota    = array();

				foreach ( $selected_order->get_items() as $item_key => $item ) {
					$descrizione     = $item->get_name();
					$prezzo_unitario = number_format( $item['subtotal'], 2 );
					$quantity        = $item->get_quantity();
					$prezzo_totale   = number_format( $quantity * $prezzo_unitario, 2 );
					$imposta         = strval( number_format( $item['subtotal_tax'], 2 ) );

					// Aliquote .
					$aliquota_iva = $tax_rates[0];
					if ( ! in_array( $aliquota_iva, $aliquote, true ) ) {
						array_push( $aliquote, $aliquota_iva );
					}

					$imponibile_per_aliquota[ $aliquota_iva ] = $imponibile_per_aliquota[ $aliquota_iva ] + $prezzo_totale;
					$imposta_per_aliquota[ $aliquota_iva ]    = $imposta_per_aliquota[ $aliquota_iva ] + $imposta;

					array_push(
						$dettaglio_linee,
						array(
							'descrizione'     => $descrizione,
							'aliquota_iva'    => $aliquota_iva,
							'quantita'        => strval( number_format( $quantity, 2 ) ),
							'prezzo_totale'   => $prezzo_totale,
							'prezzo_unitario' => $prezzo_unitario,
							'numero_linea'    => $counter,
						)
					);

					array_push(
						$cart,
						array(
							'dettaglio_linea' => array(
								'descrizione'     => $descrizione,
								'aliquota_iva'    => $aliquota_iva,
								'quantita'        => strval( number_format( $quantity, 2 ) ),
								'prezzo_totale'   => $prezzo_totale,
								'prezzo_unitario' => $prezzo_unitario,
								'numero_linea'    => $counter,
							),
							'data'            => array(
								'enableRitenuta' => true,
								'enableCassa'    => true,
								'iva_calcolata'  => intval( $imposta ),
								'tot_ivato'      => $prezzo_totale + $imposta,
							),
						)
					);

					$counter++;
				}

				foreach ( $aliquote as $aliquota_iva ) {
					array_push(
						$dati_riepilogo,
						array(
							'aliquota_iva'       => $aliquota_iva,
							'imponibile_importo' => strval( number_format( $imponibile_per_aliquota[ $aliquota_iva ], 2 ) ),
							'imposta'            => strval( number_format( $imposta_per_aliquota[ $aliquota_iva ], 2 ) ),
						)
					);
				}

				/* Order Data */
				$order_data = $selected_order->get_data();

				/* URL, Header e Body */

				$baghera_url = 'https://apikey-dot-baghere-suite.ew.r.appspot.com/api_fattura';

				$fattura_elettronica_header = array(
					'dati_trasmissione'       => array(
						'codice_destinatario' => $codice_destinatario,
					),
					'cedente_prestatore'      => array(
						'dati_anagrafici' => array(
							'id_fiscale_iva' => array(
								'id_paese'  => $woocommerce_baghera_options['nazione_codice_due_caratteri_venditore'],
								'id_codice' => $woocommerce_baghera_options['partita_iva_venditore'],
							),
							'codice_fiscale' => $woocommerce_baghera_options['partita_iva_venditore'],
							'anagrafica'     => array(
								'denominazione' => $woocommerce_baghera_options['denominazione_venditore'],
							),
							'regime_fiscale' => 'RF01', // Ordinario .
						),
						'sede'            => array(
							'indirizzo' => $woocommerce_baghera_options['indirizzo_venditore'],
							'cap'       => $woocommerce_baghera_options['cap_venditore'],
							'comune'    => $woocommerce_baghera_options['comune_venditore'],
							'provincia' => $woocommerce_baghera_options['provincia_codice_due_caratteri_venditore'],
							'nazione'   => $woocommerce_baghera_options['nazione_codice_due_caratteri_venditore'],
						),
						'contatti'        => array(
							'email' => $woocommerce_baghera_options['e_mail_venditore'],
						),
						'iscrizione_rea'  => array(
							'ufficio'            => $woocommerce_baghera_options['ufficio_rea_provincia_codice_due_caratteri_venditore'],
							'numero_rea'         => $woocommerce_baghera_options['numero_rea_venditore'],
							'capitale_sociale'   => $woocommerce_baghera_options['capitale_sociale_venditore'],
							'socio_unico'        => $woocommerce_baghera_options['socio_unico'],
							'stato_liquidazione' => $woocommerce_baghera_options['stato_liquidazione'],

						),

					),
					'cessionario_committente' => array(
						'dati_anagrafici' => array(
							'anagrafica'     => array(
								'nome'    => ucwords( strtolower( $order_data['billing']['first_name'] ) ),
								'cognome' => ucwords( strtolower( $order_data['billing']['last_name'] ) ),
							),
							'codice_fiscale' => strtoupper( get_post_meta( $selected_order->get_id(), '_billing_fiscalcode', true ) ),
						),
						'sede'            => array(
							'indirizzo' => preg_replace( '/[[:^print:]]/', '', $order_data['billing']['address_1'] ),
							'cap'       => $order_data['billing']['postcode'],
							'comune'    => $order_data['billing']['city'],
							'nazione'   => $order_data['billing']['country'],
							'provincia' => $order_data['billing']['state'],
						),

					),
				);

				$fattura_elettronica_body = array(
					array(
						'dati_beni_servizi' => array(
							'dati_riepilogo'  => $dati_riepilogo,
							'dettaglio_linee' => $dettaglio_linee,
						),
						'dati_generali'     =>
							array(
								'dati_generali_documento' => array(
									'tipo_documento' => 'TD01', // Fattura .
									'divisa'         => 'EUR',
									'data'           => gmdate( 'Y-m-d' ),
									'causale'        => array( 'Saldo acquisto del ' . $order_data['date_created']->date( 'Y-m-d' ) ),
									'importo_totale_documento' => $order_data['total'],
								),
							),
						'dati_pagamento'    => array(
							array(
								'condizioni_pagamento' => 'TP02', // Completo .
								'dettaglio_pagamento'  => array(
									array(
										'codice_pagamento' => $selected_order->get_transaction_id(),
										'modalita_pagamento' => 'MP08', // Carte di pagamento .
										'importo_pagamento' => $order_data['total'],
										'beneficiario'     => $woocommerce_baghera_options['denominazione_venditore'],
										'iban'             => $woocommerce_baghera_options['iban_venditore'],
										'istituto_finanziario' => $woocommerce_baghera_options['nome_istituto_finanziario_venditore'],
									),
								),
							),
						),
					),
				);

				$plugin_data    = get_plugin_data( __FILE__ );
				$plugin_version = $plugin_data['Version'];

				if ( isset( $_SERVER['SERVER_ADDR'] ) ) {
					$ip = filter_var( wp_unslash( $_SERVER['SERVER_ADDR'] ), FILTER_VALIDATE_IP );
				} else {
					$ip = null;
				}

				if ( isset( $_SERVER['SERVER_NAME'] ) ) {
					$website = sanitize_url( wp_unslash( $_SERVER['SERVER_NAME'] ) );
				} else {
					$website = null;
				}

				$baghera_request = array(
					'origin'                     => 'WP_PLUGIN',
					'origin_metadata'            => array(
						'ip'             => $ip,
						'website'        => $website,
						'plugin_version' => $plugin_version,
					),
					'tipo'                       => 'outbound',
					'autoNumber'                 => 'true',
					'category_id'                => 78,
					'cart'                       => array(
						'cart' => $cart,
					),
					'fattura_elettronica_header' => $fattura_elettronica_header,
					'fattura_elettronica_body'   => $fattura_elettronica_body,
				);

				$args = array(
					'headers'     => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
						'API-KEY'      => $woocommerce_baghera_options['api_key'],
					),
					// wp_json_encode() was problematic .
					'body'        => json_encode( $baghera_request ),
					'data_format' => 'body',
					'timeout'     => 30,

				);

				if ( $this->debug ) {
					print_r( $args );
					die();
				} else {
					$pre_result     = wp_remote_post( $baghera_url, $args );
					$baghera_result = json_decode( $pre_result['body'], true );
				}
			}

			if ( true == $baghera_result['success'] ) {
				$updated_meta = update_post_meta( $order_id, 'fattura_creata', $baghera_result['fattura_id'] );
				?>
	<div class="notice notice-success is-dismissible">
		<p><strong>Fattura creata su Baghera.it </p>
	</div>
				<?php
			}

			unset( $order_id );
			if ( isset( $_SERVER['HTTP_REFERER'] ) && ! $internal_call ) {
				header( 'Location: ' . esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
			}
		}
	}
}

if ( is_admin() ) {
	$woocommerce_baghera = new Woocommerce_Baghera();
}
