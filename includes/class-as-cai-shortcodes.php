<?php
/**
 * Shortcodes — Verfügbarkeits-Anzeige für Elementor Loop und andere Kontexte.
 *
 * [as_cai_availability]                      → Badge (Standard), aktuelles Produkt
 * [as_cai_availability product_id="123"]     → Spezifisches Produkt
 * [as_cai_availability display="badge"]      → Farbiger Badge
 * [as_cai_availability display="bar"]        → Mini-Progress-Bar
 * [as_cai_availability display="text"]       → Nur Text
 * [as_cai_availability display="count"]      → Nur Zahl
 *
 * Zeigt vor Verkaufsstart automatisch einen Countdown-Timer statt der
 * Verfügbarkeitsdaten an. Der Timer aktualisiert sich live per JavaScript
 * und wechselt beim Ablauf automatisch zur Verfügbarkeitsanzeige.
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.3.78
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AS_CAI_Shortcodes {

	/** @var AS_CAI_Shortcodes|null */
	private static $instance = null;

	/** @var bool Track if CSS has been enqueued */
	private static $css_enqueued = false;

	/** @var bool Track if countdown JS has been enqueued */
	private static $js_enqueued = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'as_cai_availability', array( $this, 'shortcode_handler' ) );

		// AJAX for admin builder preview.
		add_action( 'wp_ajax_as_cai_shortcode_preview', array( $this, 'ajax_shortcode_preview' ) );
	}

	/**
	 * Shortcode handler: [as_cai_availability]
	 */
	public function shortcode_handler( $atts ) {
		$atts = shortcode_atts( array(
			'product_id' => 0,
			'display'    => 'badge',
		), $atts, 'as_cai_availability' );

		$product_id = absint( $atts['product_id'] );

		// Fallback: Aktuelles Produkt im Loop.
		if ( ! $product_id ) {
			global $product;
			if ( $product && is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$product_id = $product->get_id();
			} elseif ( get_the_ID() ) {
				$product_id = get_the_ID();
			}
		}

		if ( ! $product_id ) {
			return '';
		}

		// Enqueue CSS einmalig.
		if ( ! self::$css_enqueued ) {
			self::enqueue_shortcode_css();
			self::$css_enqueued = true;
		}

		// Prüfe ob der Verkauf noch nicht gestartet hat → Countdown anzeigen.
		$countdown_html = self::maybe_render_countdown( $product_id );
		if ( $countdown_html ) {
			return $countdown_html;
		}

		// Verkauf hat gestartet → normale Verfügbarkeitsanzeige.
		$data = AS_CAI_Status_Display::get_detailed_availability_status( $product_id );
		if ( ! $data ) {
			return '';
		}

		return self::render_output( $data, $atts['display'] );
	}

	/**
	 * Check if product sale hasn't started and render countdown if so.
	 *
	 * Uses AS_CAI_Product_Availability::get_availability_data() to check
	 * if the product has a future start date.
	 *
	 * @param int $product_id Product ID.
	 * @return string|null Countdown HTML or null if sale has started.
	 */
	private static function maybe_render_countdown( $product_id ) {
		if ( ! class_exists( 'AS_CAI_Product_Availability' ) ) {
			return null;
		}

		$availability = AS_CAI_Product_Availability::instance()->get_availability_data( $product_id );

		// Kein Availability-System aktiv oder Verkauf bereits gestartet.
		if ( ! $availability || $availability['is_available'] ) {
			return null;
		}

		$start_timestamp = $availability['start_timestamp'];
		$start_date      = $availability['start_date'];
		$start_time      = $availability['start_time'];

		// Formatierte Startzeit für Anzeige.
		try {
			$wp_tz    = wp_timezone();
			$dt       = new DateTime( $start_date . ' ' . $start_time . ':00', $wp_tz );
			$date_str = wp_date( 'd.m.Y', $dt->getTimestamp() );
			$time_str = wp_date( 'H:i', $dt->getTimestamp() );
		} catch ( Exception $e ) {
			$date_str = $start_date;
			$time_str = $start_time;
		}

		// Admin Template-Builder Einstellungen laden.
		$text_before  = get_option( 'as_cai_sc_cd_text_before', 'Verkaufsstart in' );
		$text_after   = get_option( 'as_cai_sc_cd_text_after', '{date} um {time} Uhr' );
		$show_days    = get_option( 'as_cai_sc_cd_show_days', 'yes' ) === 'yes';
		$show_hours   = get_option( 'as_cai_sc_cd_show_hours', 'yes' ) === 'yes';
		$show_minutes = get_option( 'as_cai_sc_cd_show_minutes', 'yes' ) === 'yes';
		$show_seconds = get_option( 'as_cai_sc_cd_show_seconds', 'no' ) === 'yes';
		$font_size    = absint( get_option( 'as_cai_sc_cd_font_size', '12' ) );

		if ( $font_size < 9 ) {
			$font_size = 9;
		} elseif ( $font_size > 18 ) {
			$font_size = 18;
		}

		// Platzhalter im After-Text ersetzen.
		$after_text = str_replace(
			array( '{date}', '{time}' ),
			array( $date_str, $time_str ),
			$text_after
		);

		// Enqueue countdown JS einmalig.
		if ( ! self::$js_enqueued ) {
			self::enqueue_countdown_js( $show_days, $show_hours, $show_minutes, $show_seconds );
			self::$js_enqueued = true;
		}

		$unique_id = 'as-cai-cd-' . $product_id;
		$css_class = 'as-cai-sc as-cai-sc-countdown';

		$html  = '<div class="' . esc_attr( $css_class ) . '" id="' . esc_attr( $unique_id ) . '"';
		$html .= ' data-target-timestamp="' . esc_attr( $start_timestamp ) . '"';
		$html .= ' data-product-id="' . esc_attr( $product_id ) . '"';
		$html .= ' data-show-days="' . ( $show_days ? '1' : '0' ) . '"';
		$html .= ' data-show-hours="' . ( $show_hours ? '1' : '0' ) . '"';
		$html .= ' data-show-minutes="' . ( $show_minutes ? '1' : '0' ) . '"';
		$html .= ' data-show-seconds="' . ( $show_seconds ? '1' : '0' ) . '"';
		$html .= ' style="font-size:' . esc_attr( $font_size ) . 'px;">';

		// Text davor.
		if ( '' !== $text_before ) {
			$html .= '<span class="as-cai-sc-cd-label">' . esc_html( $text_before ) . '</span> ';
		}

		// Timer-Einheiten.
		$html .= '<span class="as-cai-sc-cd-timer">';
		$units = array();
		if ( $show_days ) {
			$units[] = '<span class="cd-d" data-unit="d">--</span>T';
		}
		if ( $show_hours ) {
			$units[] = '<span class="cd-h" data-unit="h">--</span>S';
		}
		if ( $show_minutes ) {
			$units[] = '<span class="cd-m" data-unit="m">--</span>M';
		}
		if ( $show_seconds ) {
			$units[] = '<span class="cd-s" data-unit="s">--</span>S';
		}
		$html .= implode( ' ', $units );
		$html .= '</span>';

		// Text danach.
		if ( '' !== $after_text ) {
			$html .= ' <span class="as-cai-sc-cd-date">' . esc_html( $after_text ) . '</span>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the shortcode output.
	 *
	 * @param array  $data    Status data from get_detailed_availability_status()
	 * @param string $display Display mode: badge, bar, text, count
	 * @return string HTML output
	 */
	public static function render_output( $data, $display = 'badge' ) {
		$status    = $data['status'];
		$label     = $data['label'] ?? 'Einheiten';
		$available = $data['available'];
		$total     = $data['total'];
		$pct       = $data['percent_free'];

		// Einheitliche Farbpalette — Dark Theme mit Gold-Akzent.
		$status_colors = array(
			'available'     => '#B19E63',
			'limited'       => '#B19E63',
			'critical'      => '#c4433e',
			'reserved_full' => '#54595F',
			'sold_out'      => '#c4433e',
		);
		$color = isset( $status_colors[ $status ] ) ? $status_colors[ $status ] : '#54595F';

		$status_icons = array(
			'available'     => '●',
			'limited'       => '●',
			'critical'      => '●',
			'reserved_full' => '●',
			'sold_out'      => '●',
		);
		$icon = isset( $status_icons[ $status ] ) ? $status_icons[ $status ] : '●';

		// Status-Dot HTML.
		$dot = '<span class="as-cai-sc-dot" style="background:' . esc_attr( $color ) . ';"></span>';

		switch ( $display ) {
			case 'count':
				return '<span class="as-cai-sc as-cai-sc-count">' . esc_html( $available ) . '</span>';

			case 'text':
				if ( 'sold_out' === $status ) {
					return '<span class="as-cai-sc as-cai-sc-text as-cai-sc-status-' . esc_attr( $status ) . '">' . $dot . ' Ausgebucht</span>';
				}
				return '<span class="as-cai-sc as-cai-sc-text">' . $dot . ' ' . esc_html( $available ) . ' von ' . esc_html( $total ) . ' ' . esc_html( $label ) . ' verfügbar</span>';

			case 'bar':
				$html  = '<div class="as-cai-sc as-cai-sc-bar">';
				$html .= '<div class="as-cai-sc-bar-track">';
				$html .= '<div class="as-cai-sc-bar-fill" style="width:' . esc_attr( $pct ) . '%;background:' . esc_attr( $color ) . ';"></div>';
				$html .= '</div>';
				if ( 'sold_out' === $status ) {
					$html .= '<span class="as-cai-sc-bar-label">Ausgebucht</span>';
				} else {
					$html .= '<span class="as-cai-sc-bar-label">' . esc_html( round( $pct ) ) . '% verfügbar (' . esc_html( $available ) . '/' . esc_html( $total ) . ')</span>';
				}
				$html .= '</div>';
				return $html;

			case 'badge':
			default:
				$html = '<span class="as-cai-sc as-cai-sc-badge as-cai-sc-status-' . esc_attr( $status ) . '">';
				if ( 'sold_out' === $status ) {
					$html .= $dot . ' Ausgebucht';
				} else {
					$html .= $dot . ' ' . esc_html( $available ) . ' von ' . esc_html( $total ) . ' ' . esc_html( $label );
				}
				$html .= '</span>';
				return $html;
		}
	}

	/**
	 * Enqueue inline CSS for shortcode output.
	 */
	private static function enqueue_shortcode_css() {
		$css = '
		/* ── Einheitliche Designsprache: Dark Theme + Gold (#B19E63) ── */
		.as-cai-sc {
			font-family: inherit;
			-webkit-font-smoothing: antialiased;
		}

		/* Status-Dot — pulsierender Kreis */
		.as-cai-sc-dot {
			display: inline-block;
			width: 8px; height: 8px;
			border-radius: 50%;
			flex-shrink: 0;
			animation: as-cai-sc-pulse 1.5s ease-in-out infinite;
		}
		@keyframes as-cai-sc-pulse {
			0%, 100% { opacity: 1; transform: scale(1); }
			50% { opacity: 0.6; transform: scale(1.15); }
		}

		/* ── Badge (Standard) ── */
		.as-cai-sc-badge {
			display: inline-flex; align-items: center; gap: 6px;
			padding: 5px 12px; border-radius: 8px; font-size: 13px; font-weight: 600;
			line-height: 1.4; white-space: nowrap;
			background: #25282B; color: #F8F8F8;
			border: 1px solid rgba(177, 158, 99, 0.2);
		}
		.as-cai-sc-status-available { border-left: 3px solid #B19E63; }
		.as-cai-sc-status-limited { border-left: 3px solid #B19E63; }
		.as-cai-sc-status-critical { border-left: 3px solid #c4433e; }
		.as-cai-sc-status-reserved_full { border-left: 3px solid #54595F; }
		.as-cai-sc-status-sold_out { border-left: 3px solid #c4433e; }

		/* ── Text-Modus ── */
		.as-cai-sc-text {
			display: inline-flex; align-items: center; gap: 6px;
			font-size: 13px; font-weight: 600; color: #F8F8F8;
			background: #25282B; padding: 5px 12px; border-radius: 8px;
			border: 1px solid rgba(177, 158, 99, 0.2);
		}

		/* ── Count-Modus ── */
		.as-cai-sc-count {
			font-size: 16px; font-weight: 700; color: #B19E63;
			background: #25282B; padding: 4px 10px; border-radius: 8px;
			border: 1px solid rgba(177, 158, 99, 0.2);
		}

		/* ── Bar-Modus ── */
		.as-cai-sc-bar {
			display: flex; flex-direction: column; gap: 4px; width: 100%;
			background: #25282B; padding: 8px 12px; border-radius: 8px;
			border: 1px solid rgba(177, 158, 99, 0.2);
		}
		.as-cai-sc-bar-track {
			width: 100%; height: 6px; background: rgba(255, 255, 255, 0.1);
			border-radius: 3px; overflow: hidden;
		}
		.as-cai-sc-bar-fill {
			height: 100%; border-radius: 3px; transition: width 0.5s ease;
		}
		.as-cai-sc-bar-label { font-size: 12px; color: rgba(248, 248, 248, 0.6); }

		/* ── Countdown — font-size per inline style ── */
		.as-cai-sc-countdown {
			display: inline-flex; align-items: center; gap: 0.4em; flex-wrap: wrap;
			padding: 5px 12px; border-radius: 8px; font-weight: 600;
			line-height: 1.4; white-space: nowrap;
			background: #25282B; color: #F8F8F8;
			border: 1px solid rgba(177, 158, 99, 0.2);
		}
		.as-cai-sc-cd-label { color: rgba(248, 248, 248, 0.6); font-weight: 500; }
		.as-cai-sc-cd-timer {
			font-variant-numeric: tabular-nums;
			letter-spacing: 0.5px;
			color: #B19E63;
			font-weight: 700;
		}
		.as-cai-sc-cd-timer [data-unit] { min-width: 1.2em; display: inline-block; text-align: center; }
		.as-cai-sc-cd-date { color: rgba(248, 248, 248, 0.5); font-weight: 400; font-size: 0.9em; }

		/* Wenn Countdown abgelaufen — sanfter Übergang */
		.as-cai-sc-countdown.expired { display: none; }
		';
		wp_register_style( 'as-cai-shortcode-inline', false );
		wp_enqueue_style( 'as-cai-shortcode-inline' );
		wp_add_inline_style( 'as-cai-shortcode-inline', $css );
	}

	/**
	 * Enqueue the countdown JavaScript for shortcode timers.
	 *
	 * Lightweight inline script that:
	 * - Finds all .as-cai-sc-countdown elements
	 * - Calculates remaining time from data-target-timestamp
	 * - Updates the timer every second
	 * - On expiry: reloads the page so the availability badge appears
	 */
	private static function enqueue_countdown_js( $show_days = true, $show_hours = true, $show_minutes = true, $show_seconds = false ) {
		// Tick every second if seconds are shown, otherwise every 10 seconds.
		$interval = $show_seconds ? 1000 : 10000;
		$js = <<<JS
(function(){
	function initShortcodeCountdowns() {
		var els = document.querySelectorAll('.as-cai-sc-countdown');
		if (!els.length) return;

		function pad(n) { return n < 10 ? '0' + n : '' + n; }

		function tick() {
			var now = Math.floor(Date.now() / 1000);
			var anyActive = false;

			els.forEach(function(el) {
				if (el.classList.contains('expired')) return;

				var target = parseInt(el.getAttribute('data-target-timestamp'), 10);
				var diff = target - now;

				if (diff <= 0) {
					el.classList.add('expired');
					setTimeout(function() { location.reload(); }, 1500);
					return;
				}

				anyActive = true;

				var hasDays = el.getAttribute('data-show-days') === '1';
				var hasHours = el.getAttribute('data-show-hours') === '1';
				var hasMin = el.getAttribute('data-show-minutes') === '1';
				var hasSec = el.getAttribute('data-show-seconds') === '1';

				var d = Math.floor(diff / 86400);
				var remainder = diff % 86400;
				var h = Math.floor(remainder / 3600);
				var m = Math.floor((remainder % 3600) / 60);
				var s = remainder % 60;

				// If days hidden, roll into hours.
				if (!hasDays) { h += d * 24; d = 0; }
				// If hours hidden, roll into minutes.
				if (!hasHours) { m += h * 60; h = 0; }
				// If minutes hidden, roll into seconds.
				if (!hasMin) { s += m * 60; m = 0; }

				var dEl = el.querySelector('.cd-d');
				var hEl = el.querySelector('.cd-h');
				var mEl = el.querySelector('.cd-m');
				var sEl = el.querySelector('.cd-s');

				if (dEl) dEl.textContent = d;
				if (hEl) hEl.textContent = pad(h);
				if (mEl) mEl.textContent = pad(m);
				if (sEl) sEl.textContent = pad(s);
			});

			if (anyActive) {
				setTimeout(tick, {$interval});
			}
		}

		tick();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initShortcodeCountdowns);
	} else {
		initShortcodeCountdowns();
	}
})();
JS;
		wp_register_script( 'as-cai-sc-countdown', false, array(), AS_CAI_VERSION, true );
		wp_enqueue_script( 'as-cai-sc-countdown' );
		wp_add_inline_script( 'as-cai-sc-countdown', $js );
	}

	/**
	 * AJAX handler: Live preview for admin builder.
	 */
	public function ajax_shortcode_preview() {
		check_ajax_referer( 'as_cai_shortcode_builder', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Nicht autorisiert' );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$display    = isset( $_POST['display'] ) ? sanitize_text_field( $_POST['display'] ) : 'badge';

		// Simulation mode.
		if ( ! empty( $_POST['simulate'] ) ) {
			$data = array(
				'status'       => sanitize_text_field( $_POST['sim_status'] ?? 'available' ),
				'total'        => absint( $_POST['sim_total'] ?? 45 ),
				'available'    => absint( $_POST['sim_available'] ?? 20 ),
				'sold'         => absint( $_POST['sim_sold'] ?? 23 ),
				'reserved'     => absint( $_POST['sim_reserved'] ?? 2 ),
				'percent_free' => 0,
				'label'        => sanitize_text_field( $_POST['sim_label'] ?? 'Parzellen' ),
			);
			$data['percent_free'] = $data['total'] > 0 ? round( ( $data['available'] / $data['total'] ) * 100, 1 ) : 0;
		} else {
			$data = $product_id ? AS_CAI_Status_Display::get_detailed_availability_status( $product_id ) : null;
		}

		if ( ! $data ) {
			wp_send_json_error( 'Keine Daten verfügbar' );
		}

		$html = self::render_output( $data, $display );

		// Include inline CSS for preview — einheitliches Dark Theme.
		$css = '<style>
		.as-cai-sc { font-family: inherit; -webkit-font-smoothing: antialiased; }
		.as-cai-sc-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
		.as-cai-sc-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; line-height: 1.4; white-space: nowrap; background: #25282B; color: #F8F8F8; border: 1px solid rgba(177,158,99,0.2); }
		.as-cai-sc-status-available { border-left: 3px solid #B19E63; }
		.as-cai-sc-status-limited { border-left: 3px solid #B19E63; }
		.as-cai-sc-status-critical { border-left: 3px solid #c4433e; }
		.as-cai-sc-status-reserved_full { border-left: 3px solid #54595F; }
		.as-cai-sc-status-sold_out { border-left: 3px solid #c4433e; }
		.as-cai-sc-text { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: #F8F8F8; background: #25282B; padding: 5px 12px; border-radius: 8px; border: 1px solid rgba(177,158,99,0.2); }
		.as-cai-sc-count { font-size: 16px; font-weight: 700; color: #B19E63; background: #25282B; padding: 4px 10px; border-radius: 8px; border: 1px solid rgba(177,158,99,0.2); }
		.as-cai-sc-bar { display: flex; flex-direction: column; gap: 4px; width: 100%; background: #25282B; padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(177,158,99,0.2); }
		.as-cai-sc-bar-track { width: 100%; height: 6px; background: rgba(255,255,255,0.1); border-radius: 3px; overflow: hidden; }
		.as-cai-sc-bar-fill { height: 100%; border-radius: 3px; transition: width 0.5s ease; }
		.as-cai-sc-bar-label { font-size: 12px; color: rgba(248,248,248,0.6); }
		</style>';

		wp_send_json_success( array(
			'html'      => $css . $html,
			'shortcode' => '[as_cai_availability' . ( $product_id ? ' product_id="' . $product_id . '"' : '' ) . ( 'badge' !== $display ? ' display="' . $display . '"' : '' ) . ']',
		) );
	}
}
