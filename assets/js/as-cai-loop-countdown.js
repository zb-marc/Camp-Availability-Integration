/**
 * Camp Availability Integration - Loop Countdown
 *
 * Updates countdown timers on category/shop pages for unavailable products.
 *
 * @package AS_Camp_Availability_Integration
 * @since 1.3.37
 * @since 1.3.65 Removed debug console.log spam; only logs when asCaiDebug is true
 */

(function($) {
	'use strict';

	var countdownInterval = null;
	var isDebug = typeof asCaiDebug !== 'undefined' && asCaiDebug;

	function log() {
		if (isDebug && typeof console !== 'undefined') {
			console.log.apply(console, arguments);
		}
	}

	/**
	 * Update countdown for a single button.
	 */
	function updateCountdown($button) {
		var targetTimestamp = parseInt($button.attr('data-target-timestamp'), 10);

		if (!targetTimestamp) {
			return;
		}

		var now = Math.floor(Date.now() / 1000);
		var secondsLeft = targetTimestamp - now;

		// If time is up, reload page to show normal button
		if (secondsLeft <= 0) {
			log('[AS-CAI] Countdown expired, reloading...');
			if (typeof sessionStorage !== 'undefined') {
				sessionStorage.removeItem('wc_fragments');
				sessionStorage.removeItem('wc_cart_hash');
				sessionStorage.removeItem('wc_cart_created');
			}
			location.replace(location.href.split('#')[0] + (location.href.indexOf('?') > -1 ? '&' : '?') + '_nocache=' + Date.now());
			return;
		}

		// Calculate time units
		var days = Math.floor(secondsLeft / 86400);
		var hours = Math.floor((secondsLeft % 86400) / 3600);
		var minutes = Math.floor((secondsLeft % 3600) / 60);
		var seconds = secondsLeft % 60;

		// Build short countdown text
		var countdownText = '';
		if (days > 0) {
			countdownText += days + 'T ';
		}
		if (hours > 0 || days > 0) {
			countdownText += hours + 'S ';
		}
		if (minutes > 0 || hours > 0 || days > 0) {
			countdownText += minutes + 'M ';
		}
		countdownText += seconds + 'S';

		$button.text(countdownText.trim());
	}

	/**
	 * Update all countdown buttons.
	 */
	function updateAllCountdowns() {
		var $buttons = $('.as-cai-loop-button-disabled[data-target-timestamp]');
		$buttons.each(function() {
			updateCountdown($(this));
		});
	}

	/**
	 * Initialize countdown interval.
	 */
	function initCountdowns() {
		if (countdownInterval) {
			clearInterval(countdownInterval);
		}

		updateAllCountdowns();

		countdownInterval = setInterval(function() {
			updateAllCountdowns();
		}, 1000);
	}

	/**
	 * Stop countdown interval.
	 */
	function stopCountdowns() {
		if (countdownInterval) {
			clearInterval(countdownInterval);
			countdownInterval = null;
		}
	}

	$(document).ready(function() {
		initCountdowns();
	});

	$(document.body).on('updated_wc_div', function() {
		initCountdowns();
	});

	$(document.body).on('wc_fragments_refreshed', function() {
		initCountdowns();
	});

	$(window).on('beforeunload', function() {
		stopCountdowns();
	});

})(jQuery);
