<?php
/**
 * Temporary mock credit-card payment gateway.
 *
 * Looks and behaves like a real card gateway at checkout (card-style fields,
 * normal order-processing flow) but never contacts a payment processor and
 * never charges anything, and never stores the entered card details anywhere.
 * Meant to be swapped out for a real Israeli processor (Tranzila/Cardcom/etc.)
 * before the store accepts real orders — every order paid through it gets a
 * private admin-only order note flagging that, and wp-admin shows a
 * persistent notice while it's enabled.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'woocommerce_payment_gateways',
	static function ( array $gateways ): array {
		$gateways[] = 'Omef_Mock_Card_Gateway';
		return $gateways;
	}
);

add_action( 'plugins_loaded', 'omef_init_mock_card_gateway_class' );

function omef_init_mock_card_gateway_class(): void {
	if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'Omef_Mock_Card_Gateway' ) ) {
		return;
	}

	class Omef_Mock_Card_Gateway extends WC_Payment_Gateway {
		public function __construct() {
			$this->id                 = 'omef_mock_card';
			$this->has_fields         = true;
			$this->method_title       = 'כרטיס אשראי (זמני — לא מחובר לסליקה)';
			$this->method_description = 'שער תשלום זמני: מציג ללקוח טופס כרטיס אשראי ומשלים הזמנות כרגיל, אך אינו מבצע חיוב אמיתי, אינו מחובר לחברת סליקה, ואינו שומר את פרטי הכרטיס שמוזנים. יש להחליף אותו בשער תשלום אמיתי (למשל טרנזילה / קארדקום) לפני שהחנות מתחילה לקבל הזמנות אמיתיות.';

			$this->init_form_fields();
			$this->init_settings();

			$this->title       = $this->get_option( 'title', 'כרטיס אשראי' );
			$this->description = $this->get_option( 'description', 'התשלום מאובטח ומעובד בעת אישור ההזמנה.' );
			$this->enabled     = $this->get_option( 'enabled', 'yes' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public function init_form_fields(): void {
			$this->form_fields = array(
				'enabled'     => array(
					'title'   => 'הפעלה',
					'type'    => 'checkbox',
					'label'   => 'הפעלת שער התשלום הזמני',
					'default' => 'yes',
				),
				'title'       => array(
					'title'       => 'כותרת ללקוח',
					'type'        => 'text',
					'default'     => 'כרטיס אשראי',
					'desc_tip'    => true,
					'description' => 'הטקסט שיוצג ללקוח בעמוד התשלום.',
				),
				'description' => array(
					'title'       => 'תיאור ללקוח',
					'type'        => 'textarea',
					'default'     => 'התשלום מאובטח ומעובד בעת אישור ההזמנה.',
					'desc_tip'    => true,
					'description' => 'טקסט קצר שיוצג מתחת לכותרת בעמוד התשלום.',
				),
			);
		}

		public function payment_fields(): void {
			if ( $this->description ) {
				echo '<p>' . esc_html( $this->description ) . '</p>';
			}
			?>
			<fieldset id="omef-mock-card-fields" class="omef-mock-card-fields">
				<p class="form-row form-row-wide validate-required">
					<label for="omef_mock_card_number">מספר כרטיס&nbsp;<span class="required">*</span></label>
					<input type="text" class="input-text" inputmode="numeric" autocomplete="cc-number"
						id="omef_mock_card_number" name="omef_mock_card_number"
						placeholder="0000 0000 0000 0000" maxlength="19" required>
				</p>
				<p class="form-row form-row-first validate-required">
					<label for="omef_mock_card_expiry">תוקף (MM/YY)&nbsp;<span class="required">*</span></label>
					<input type="text" class="input-text" inputmode="numeric" autocomplete="cc-exp"
						id="omef_mock_card_expiry" name="omef_mock_card_expiry"
						placeholder="MM/YY" maxlength="5" required>
				</p>
				<p class="form-row form-row-last validate-required">
					<label for="omef_mock_card_cvc">CVC&nbsp;<span class="required">*</span></label>
					<input type="text" class="input-text" inputmode="numeric" autocomplete="cc-csc"
						id="omef_mock_card_cvc" name="omef_mock_card_cvc"
						placeholder="123" maxlength="4" required>
				</p>
				<div class="clear"></div>
			</fieldset>
			<?php
		}

		public function process_payment( $order_id ) {
			$number = preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['omef_mock_card_number'] ?? '' ) ) );
			$expiry = sanitize_text_field( wp_unslash( $_POST['omef_mock_card_expiry'] ?? '' ) );
			$cvc    = sanitize_text_field( wp_unslash( $_POST['omef_mock_card_cvc'] ?? '' ) );

			if ( ! preg_match( '/^\d{12,19}$/', (string) $number )
				|| ! preg_match( '/^\d{2}\/\d{2}$/', $expiry )
				|| ! preg_match( '/^\d{3,4}$/', $cvc )
			) {
				wc_add_notice( 'פרטי הכרטיס שהוזנו אינם תקינים.', 'error' );
				return array( 'result' => 'failure' );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wc_add_notice( 'אירעה שגיאה בעיבוד ההזמנה.', 'error' );
				return array( 'result' => 'failure' );
			}

			// Intentionally never touches $number/$expiry/$cvc beyond format
			// validation above — nothing card-shaped gets persisted anywhere.
			$order->add_order_note(
				'שולם דרך שער תשלום זמני (מוק) — לא בוצע חיוב אמיתי. יש להחליף בשער תשלום אמיתי לפני קבלת הזמנות אמיתיות.',
				false
			);
			$order->payment_complete();
			WC()->cart->empty_cart();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}
}

add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WC_Payment_Gateways' ) ) {
			return;
		}
		$gateways = WC_Payment_Gateways::instance()->payment_gateways();
		if ( empty( $gateways['omef_mock_card'] ) || 'yes' !== $gateways['omef_mock_card']->enabled ) {
			return;
		}
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=omef_mock_card' );
		echo '<div class="notice notice-warning"><p><strong>שער התשלום הזמני (מוק) פעיל.</strong> הזמנות שמשולמות דרכו אינן מחייבות כרטיס אשראי אמיתי. יש להחליף אותו בשער תשלום אמיתי לפני שהחנות פתוחה להזמנות אמיתיות. <a href="' . esc_url( $settings_url ) . '">להגדרות שער התשלום</a></p></div>';
	}
);
