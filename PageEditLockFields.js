/**
 * Page Edit Lock Fields JS
 *
 * Lock specific fields in the page editor to make them non-editable.
 *
 * Copyright (C) 2023 by Ryan Cramer Design, LLC / ProcessWire
 *
 * License MPL 2.0
 *
 */
function PageEditLockFields() {
	
	let settings = ProcessWire.config['PageEditLockFields'];

	// get the icon <i> element for the given .Inputfield
	function getInputfieldIcon($f) {
		let $h = $f.children('.InputfieldHeader').first();
		if(!$h.length) {
			console.error('Cannot find InputfieldHeader for ' + $f.prop('id'));
			return $('<i></i>');
		}
		let html = $h.html();
		let $icon;
		if(html.indexOf('<i ') === 0) {
			$icon = $h.children('i').first();
		} else {
			$icon = $('<i></i>');
			$h.prepend($icon);
		}
		return $icon;
	}

	// set the .Inputfield to have a new named icon
	function setInputfieldIcon($f, iconName) {
		let $icon = getInputfieldIcon($f);
		let cls = $icon.attr('class');
		$icon.attr('class', 'fa fa-fw fa-' + iconName);
		if(typeof cls !== 'undefined' && cls.length) {
			$icon.attr('data-prev-class', cls);
		}
	}

	// restore the .Inputfield to have its original icon
	function restoreInputfieldIcon($f, iconDefault) {
		let $icon = getInputfieldIcon($f);
		let prevClass = $icon.attr('data-prev-class');
		
		if(typeof prevClass === 'undefined' || !prevClass.length) {
			// there was no previous icon
			if(typeof iconDefault != 'undefined') {
				$icon.addClass('fa fa-fw fa-' + iconDefault);
			} else {
				$icon.remove();
			}
		} else {
			$icon.attr('class', prevClass);
		}
	}

	// set given .Inputfield to have the given .description text
	function setInputfieldDescription($f, description) {
		let $content = $f.children('.InputfieldContent');
		if(!$content.length) return;
		let $desc = $content.children('.description').first();
		if($desc.length) {
			$desc.data('prev-value', $desc.html());
		} else {
			$desc = $("<p class='description'></p>");
			$content.prepend($desc);
		}
		$desc.text(description);
	}

	// restore given .Inputfield to have its original .description text
	function restoreInputfieldDescription($f) {
		let $content = $f.children('.InputfieldContent');
		if(!$content.length) return;
		let $desc = $content.children('.description').first();
		if(!$desc.length) return;
		let prev = $desc.data('prev-value');
		if(typeof prev !== 'undefined' && prev.length) {
			$desc.html(prev);
		} else {
			$desc.remove();
		}
	}

	// lock the given .Inputfield element
	function lockInputfield($f) {
		let cid = checkboxId(Inputfields.name($f));
		$('#' + cid).prop('checked', true);
		$f.addClass('InputfieldIsLocked');
		setInputfieldIcon($f, 'lock');
		setInputfieldDescription($f, settings['lockDesc']); 
		if(settings['useMinimize']) Inputfields.close($f);
	}

	// unlock the given .Inputfield element
	function unlockInputfield($f) {
		let cid = checkboxId(Inputfields.name($f));
		$('#' + cid).prop('checked', false);
		$f.removeClass('InputfieldIsLocked');
		restoreInputfieldIcon($f, 'unlock');
		restoreInputfieldDescription($f);
		if($f.hasClass('InputfieldIsLockedAtStart')) {
			setInputfieldDescription($f, settings['unlockDesc']); 
		}
		if(settings['useMinimize']) Inputfields.open($f);
	}

	// given a field or inputfield name return the matching .Inputfield element
	function getInputfield(name) {
		name = inputfieldName(name);
		let id = '#wrap_Inputfield_' + name;
		let $f = $(id);
		if(!$f.length) {
			id = '#wrap_' + name;
			$f = $(id);
		}
		if(!$f.length) console.log('Cannot find Inputfield: ' + id);
		return $f;
	}

	// setup the lock alerts/dialogs when enabled
	function setupLockAlerts() {
		
		let allowAlert = true;
		
		function lockAlert() {
			if(!allowAlert) return;
			allowAlert = false;
			ProcessWire.alert(settings['lockedLabel']);
			setTimeout(function() { allowAlert = true }, 1000);
			return false;
		}
		
		function disableInputs($target) {
			$(':input', $target).prop('disabled', true);
		}
		
		if(settings['renderInputsFor'].length) {
			let sel = 
				'.InputfieldIsLockedAtStart.InputfieldIsLockedButRendered > ' + 
				'.InputfieldContent :input';
			$(document).on('input click', sel, function() { return lockAlert(); });
			sel = '.InputfieldIsLockedAtStart.InputfieldIsLockedButRendered';
			$(document).on('change', sel, function() { return lockAlert(); });
			sel = '.InputfieldIsLockedAtStart:not(.InputfieldIsLockedButRendered)';
			$(document).on('click', sel, function() { lockAlert(); });
			$('.InputfieldIsLockedButRendered').each(function() { disableInputs($(this)); });
		} else {
			$(document).on('click', '.InputfieldIsLockedAtStart', function() { lockAlert(); });
		}
		
		$(document).on('reloaded', '.Inputfield', function() {
			$('.InputfieldIsLockedButRendered', $(this)).each(function() { disableInputs($(this)); });
		});
	}

	// setup the "Locked fields" checkboxes on page editor Settings tab
	function setupLockCheckboxes() {
		// monitor checkbox changes and apply them to Inputfields
		$('#wrap__pelf').on('change', 'input[type=checkbox]', function() {
			let $c = $(this);
			let name = $c.val();
			let $f = getInputfield(name);
			if(!$f.length) return;
			if($c.prop('checked')) {
				lockInputfield($f);
			} else {
				unlockInputfield($f);
			}
		});
	}

	// translate field name to inputfield name when different
	function inputfieldName(name) {
		if(name === 'name') return '_pw_page_name';
		return name;
	}

	// translate name from inputfield name to field name when different
	function fieldName(name) {
		if(name === '_pw_page_name') name = 'name';
		return name;
	}

	// get id attribute for checkbox name
	function checkboxId(name) {
		name = fieldName(name);
		return '_pelf_' + name;
	}
	
	// get checkbox for Inputfield
	function getCheckboxForInputfield($f) {
		let name = Inputfields.name($f);
		let $checkbox = $('#' + checkboxId(name));
		if(!$checkbox.length) console.log('Cannot find: _pelf_' + name);
		return $checkbox;
	}

	// long click event handler
	let longclickEvent = function(e) {
		if($(e.target).hasClass('ui-slider-handle')) return; // i.e. InputfieldImage resize handle
		
		let $f = $(this).closest('.Inputfield');
		let $c = getCheckboxForInputfield($f);
		
		if(!$c.length) {
			// there is no checkbox for this Inputfield
			ProcessWire.alert(settings['notLockableLabel']);
			
		} else if($f.hasClass('InputfieldIsLocked')) {
			// Inputfield is already locked
			ProcessWire.confirm(settings['unlockLabel'], function() {
				unlockInputfield($f);
			});
			
		} else {
			ProcessWire.confirm(settings['lockLabel'], function() {
				lockInputfield($f);
			});
		}
		
		return false;
	};

	/*** INIT ***************************************/
	
	if(settings['useLongclick']) {
		$(document).on(
			'longclick', 
			'.InputfieldHeader:not(.InputfieldRepeaterHeaderInit)', 
			longclickEvent
		);
	}
	
	setupLockCheckboxes();

	if(settings['jumpToLocks']) {
		Inputfields.find('_pelf'); 
	}

	if(settings['useLockalert']) {
		setupLockAlerts();
	}
}

jQuery(document).ready(function() {
	PageEditLockFields();
});