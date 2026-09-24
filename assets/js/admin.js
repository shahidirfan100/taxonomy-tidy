(function () {
	'use strict';

	function updateMergeChoices(form) {
		var source = form.querySelector( 'select[name="source_id"]' );
		var target = form.querySelector( 'select[name="target_id"]' );
		if ( ! source || ! target) {
			return;
		}
		Array.prototype.forEach.call(
			target.options,
			function (option) {
				option.disabled = option.value === source.value;
			}
		);
		if (target.value === source.value) {
			var available = Array.prototype.find.call(
				target.options,
				function (option) {
					return ! option.disabled;
				}
			);
			if (available) {
				target.value = available.value;
			}
		}
	}

	Array.prototype.forEach.call(
		document.querySelectorAll( '.tt-review-form' ),
		function (form) {
			updateMergeChoices( form );
			form.addEventListener(
				'change',
				function (event) {
					if (event.target.matches( 'select[name="source_id"], select[name="target_id"]' )) {
						updateMergeChoices( form );
					}
				}
			);
			form.addEventListener(
				'submit',
				function (event) {
					var source = form.querySelector( 'select[name="source_id"]' );
					var target = form.querySelector( 'select[name="target_id"]' );
					if (source && target && source.value === target.value) {
						event.preventDefault();
						target.focus();
					}
				}
			);
		}
	);

	Array.prototype.forEach.call(
		document.querySelectorAll( 'form[data-confirm]' ),
		function (form) {
			form.addEventListener(
				'submit',
				function (event) {
					if ( ! window.confirm( form.getAttribute( 'data-confirm' ) )) {
						event.preventDefault();
					}
				}
			);
		}
	);

	Array.prototype.forEach.call(
		document.querySelectorAll( '[data-select-all]' ),
		function (master) {
			var form = master.closest( 'form' );
			if ( ! form ) {
				return;
			}
			var selector = master.getAttribute( 'data-select-all' );
			var choices  = Array.prototype.slice.call( form.querySelectorAll( selector ) );
			var counter  = form.querySelector( '[data-selection-count]' );
			var action   = form.querySelector( '[data-bulk-action]' );
			function syncMaster() {
				var selected = choices.filter( function (choice) { return choice.checked; } ).length;
				master.checked       = choices.length > 0 && selected === choices.length;
				master.indeterminate = selected > 0 && selected < choices.length;
				if (action) {
					action.disabled = 0 === selected;
				}
				if (counter) {
					var label = 1 === selected ? counter.getAttribute( 'data-label-one' ) : counter.getAttribute( 'data-label-many' );
					counter.textContent = selected + ' ' + label;
				}
			}
			master.addEventListener(
				'change',
				function () {
					choices.forEach( function (choice) { choice.checked = master.checked; } );
					syncMaster();
				}
			);
			choices.forEach( function (choice) { choice.addEventListener( 'change', syncMaster ); } );
			syncMaster();
		}
	);
}());
