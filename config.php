<?php namespace ProcessWire;

/**
 * Page Edit Locks Fields: Config
 * 
 * Copyright (C) 2023 by Ryan Cramer Design, LLC / ProcessWire
 *
 * @param InputfieldWrapper $inputfields
 * @param PageEditLockFields $module
 *
 */
function PageEditLockFieldsConfig(InputfieldWrapper $inputfields, PageEditLockFields $module) {
	
	$config = $module->wire()->config;
	$sanitizer = $module->wire()->sanitizer;
	$pages = $module->wire()->pages;

	$f = $inputfields->InputfieldCheckboxes;
	$f->attr('name', 'toggles');
	$f->label = __('Toggles');
	$f->addOption('longclick',
		__('Enable long-click of field label/header to toggle lock/unlock.') . ' ' .
		__('You can still lock/unlock from Settings tab.')
	);
	$f->addOption('locknote',
		__('Show a note in locked fields explaining that they are locked.')
	);
	$f->addOption('lockalert',
		__('Pop up alert if user clicks in a locked field, to let them know it’s locked.')
	);
	$f->addOption('minimize',
		__('Always collapse/minimize locked fields?') . ' ' . 
		__('When used, user must click field header to open.')
	);
	$f->val($module->toggles);
	$inputfields->add($f);

	$f = $inputfields->InputfieldTextTags;
	$f->attr('name', 'lockUsers');
	$f->label = __('Users that can lock/unlock');
	$f->description =
		__('If none are selected then any users with page-lock permission on a page can lock/unlock fields on that page.');
	$f->notes = __('Allows selection of superusers or users with page-lock permission.');
	$selector =
		'templates_id=' . implode('|', $config->userTemplateIDs) . ', ' .
		'parent_id=' . implode('|', $config->usersPageIDs) . ', ' .
		'(roles.name=superuser), (permissions.name=page-edit, permissions.name=page-lock), ' .
		'include=all, status<unpublished, sort=name';
	foreach($pages->find($selector) as $u) {
		$f->addOption($u->id, $u->name);
	}
	$f->val($module->lockUsers);
	$f->collapsed = Inputfield::collapsedBlank;
	$inputfields->add($f);

	$f = $inputfields->InputfieldAsmSelect;
	$f->attr('name', 'renderInputsFor');
	$f->label = __('Render inputs for fields when locked');
	$f->description =
		__('Select fields that should always have their inputs rendered even when locked.') . ' ' .
		__('Use this in cases where a particular field’s locked output isn’t adequate.') . ' ' .
		__('Or use this if any show-if or required-if conditions depend on a field’s inputs being present.') . ' ' . 
		__('Avoid using this unless you need it, as it may create confusion.') . ' ' . 
		__('Test to make sure it works with your field(s) as expected.');
	$f->notes =
		__('A locked field selected here will still appear editable, even if not saveable.') . ' ' .
		__('For this reason, you should at least also enable the lock alerts/notes toggles.');
	$options = $module->lockProperties();
	foreach($module->wire()->fields as $field) {
		$options[$field->id] = $field->name;
	}
	asort($options);
	$f->addOptions($options);
	$f->val($module->renderInputsFor);
	$f->collapsed = Inputfield::collapsedBlank;
	$inputfields->add($f);

	$f = $inputfields->InputfieldMarkup;
	$f->attr('name', '_lockList');
	$f->label = __('Current field locks');
	$inputfields->add($f);

	$locks = $module->getAllLocks();

	if(count($locks)) {
		/** @var MarkupAdminDataTable $table */
		$table = $module->wire()->modules->get('MarkupAdminDataTable');
		$table->setEncodeEntities(false);
		$table->headerRow([
			__('Page'),
			__('Fields'),
			__('Action'),
		]);
		$editUrl = $config->urls->admin . 'page/edit/?pelf=1&id=';
		$editLabel = $sanitizer->entities1(__('Edit'));
		foreach($locks as $item) {
			$table->row([
				$sanitizer->entities($item['path']),
				$sanitizer->entities(implode(', ', $item['fields'])),
				"<a target='_blank' href='$editUrl$item[id]'>$editLabel</a>",
			]);
		}
		$f->value = $table->render();
	} else {
		$f->value = $sanitizer->entities1(__('There are currently no locks.'));
	}
}