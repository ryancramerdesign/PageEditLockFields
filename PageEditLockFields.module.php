<?php namespace ProcessWire;

/**
 * Page Edit Lock Fields
 * 
 * Lock specific fields in the page editor to make them non-editable.
 * 
 * Copyright (C) 2023 by Ryan Cramer Design, LLC / ProcessWire
 * 
 * License MPL 2.0
 * 
 * @property int[] $toggles
 * @property int[]|string $lockUsers
 * @property int[] $renderInputsFor
 * 
 */
class PageEditLockFields extends WireData implements Module, ConfigurableModule {
	
	const table = 'page_edit_lock_fields';
	
	const name = '_pelf';
	
	/**
	 * Current process module
	 * 
	 * @var null|Process
	 * 
	 */
	protected $process = null;

	/**
	 * Property ids and names
	 * 
	 * @var string[] 
	 * 
	 */
	protected $lockProperties = [
		-1 => 'name', 
		-2 => 'template',
		-3 => 'parent_id', 
		-4 => 'status',
		-5 => 'delete_page',
	];

	/**
	 * Translations for alternate names of some properties
	 * 
	 * @var string[] 
	 * 
	 */
	protected $altPropertyNames = [
		'templates_id' => 'template',
		'template_id' => 'template',
		'parent' => 'parent_id',
	];

	/**
	 * Cache from getLocks method
	 * 
	 * @var array 
	 */
	protected $locksCache = [ 
		// page_id => [ field_id => field_name ] 
	];

	/**
	 * Lock flags cache (for future use)
	 * 
	 * @var array 
	 * 
	 */
	protected $locksFlags = [ 
		// page_id => [ field_id => flags ] 
	];

	/**
	 * Adjustments of collapsed states to locked states
	 * 
	 * @var array 
	 * 
	 */
	protected $lockAdjustments = [
		Inputfield::collapsedYes => Inputfield::collapsedYesLocked,
		Inputfield::collapsedYesAjax => Inputfield::collapsedYesLocked,
		Inputfield::collapsedYesLocked => Inputfield::collapsedYesLocked,
		Inputfield::collapsedNo => Inputfield::collapsedNoLocked,
		Inputfield::collapsedBlank => Inputfield::collapsedBlankLocked,
		Inputfield::collapsedNoLocked => Inputfield::collapsedNoLocked
	];
	
	public function __construct() {
		parent::__construct();
		// toggles: longclick, locknote, lockalert, minimize
		$this->set('toggles', [ 'longclick', 'locknote' ]);
		$this->set('renderInputsFor', []); 
		$this->set('lockUsers', []);
	}

	/**
	 * API ready: add hooks
	 * 
	 */
	public function ready() {
		
		$page = $this->wire()->page;
		$process = $page->process;
		$admin = $this->wire()->config->admin;
		$user = $this->wire()->user;

		if($admin) {
			// ADMIN
			if($process == 'ProcessPageEdit') {
				// hook after form build to lock fields
				$this->addHookAfter('ProcessPageEdit::buildForm', $this, 'hookAfterBuildForm');

				if($this->hasPermission()) {
					// hook to add _pelf Inputfield to Settings tab
					$this->addHookAfter('ProcessPageEdit::buildFormSettings', $this, 'hookBuildFormSettings');

					// hook to process _pelf Inputfield on Settings tab
					$this->addHookBefore('ProcessPageEdit::processInput', $this, 'hookBeforeProcessInput');
				}
				
			} else if($process == 'ProcessPageList' || strpos("$process", 'ProcessPageLister') === 0) {
				// hook to process a page list action
				$this->addHookBefore('ProcessPageListActions::processAction', $this, 'hookPageListProcessAction');

			} else if($process == 'ProcessPageSort') {
				// hook before Page::moveable when in ProcessPageSort
				$this->addHookAfter('Page::moveable', $this, 'hookPageMoveable');
			}
		} else {
			// FRONT-END
			// alternate method when outside of page editor (covers fields only, not properties)
			$this->addHookAfter('Field::getInputfield', $this, 'hookFieldGetInputfield');
		
			// add Page::editable hook if PageFrontEdit module is potentially active
			if($user->isLoggedin() && $user->hasPermission('page-edit-front')) {
				$this->addHookAfter('Page::editable', $this, 'hookPageEditable', [ 'priority' => 999 ]); 
			}
		}
	
		// delete all locks for deleted page
		$this->addHookBefore('Pages::deleted', $this, 'hookPagesDeleted'); 
	
		// clone locks for cloned page
		$this->addHookAfter('Pages::cloned', $this, 'hookPagesCloned');
	
		// hook when a field is removed from a fieldgroup/template
		$this->addHookAfter('Fieldgroups::fieldRemoved', $this, 'hookFieldRemoved'); 
	}
	
	/*****************************************************************
	 * HOOKS
	 *
	 */

	/**
	 * Hook after Fieldgroups::fieldRemoved
	 * 
	 * Remove all locks for field in fieldgroup
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookFieldRemoved(HookEvent $event) {
		$fieldgroup = $event->arguments(0); /** @var Fieldgroup $fieldgroup */
		$field = $event->arguments(1); /** @var Field $field */
		$this->removeAllLocksForFieldInFieldgroup($field, $fieldgroup);
	}

	/**
	 * Hook after Page::editable
	 * 
	 * This is for preventing front-end edits via the PageFrontEdit module
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookPageEditable(HookEvent $event) {
		if(!$event->return) return;
		$fieldName = $event->arguments(0);
		if($fieldName instanceof Field) $fieldName = $fieldName->name;
		if(!is_string($fieldName)) return;
		$page = $event->object; /** @var Page $page */
		if($this->isLocked($page, $fieldName)) $event->return = false;
	}

	/**
	 * Hook before Page::moveable to prevent movePage in ProcessPageSort
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookPageMoveable(HookEvent $event) {
		$page = $event->object; /** @var Page $page */
		if($this->isLocked($page, 'parent_id')) $event->return = false;
	}

	/**
	 * Hook before Pages::deleted to remove locks for deleted page
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookPagesDeleted(HookEvent $event) {
		$page = $event->arguments(0); /** @var Page $page */
		$this->removeAllLocksForPage($page);
	}

	/**
	 * Hook after Pages::cloned to clone locks for cloned page
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookPagesCloned(HookEvent $event) {
		$page = $event->arguments(0); /** @var Page $page */
		$copy = $event->arguments(1); /** @var Page $copy */
		$locks = $this->getLocks($page);
		if(count($locks)) $this->addLocks($copy, $locks);
	}

	/**
	 * Hook before ProcessPageListActions::processAction
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookPageListProcessAction(HookEvent $event) {
		$page = $event->arguments(0); /** @var Page $page */
		$action = $event->arguments(1);
		$actions = [ 'pub', 'unpub', 'hide', 'unhide', 'lock', 'unlock', 'trash', 'restore' ];
		if(!in_array($action, $actions)) return;
		if(!$this->isLocked($page, 'status')) return;
		$event->replace = true;
		$event->return = [
			'action' => $action, 
			'success' => false, 
			'message' => $this->_('Action is disabled because this page’s “status” field is locked.'), 
			'updateItem' => 0,
			'remove' => false,
			'refreshChildren' => false, 
		];
	}

	/**
	 * Hook after Field::getInputfield
	 * 
	 * Adds locks when outside of the page editor, but only covers fields (not properties).
	 * This is only used if not in ProcessPageEdit. 
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookFieldGetInputfield(HookEvent $event) {
		
		$field = $event->object; /** @var Field $field */
		$page = $event->arguments(0); /** @var Page $page */
		$inputfield = $event->return;
		
		if($this->isLocked($page, $field)) $this->lockInputfield($inputfield);
	}

	/**
	 * Hook after ProcessPageEdit::buildForm
	 * 
	 * Locate Inputfields that should be locked and lock them. 
	 * 
	 * @param HookEvent $event
	 *
	 */
	public function hookAfterBuildForm(HookEvent $event) {

		$process = $event->object; /** @var ProcessPageEdit $process */
		$form = $event->return; /** @var InputfieldForm $form */
		$page = $process->getPage();
		
		foreach($this->getLocks($page) as $fieldName) {
			/** @var Inputfield $inputfield */
			$formFieldName = $this->formFieldName($fieldName);
			$f = $form->getChildByName($formFieldName);
			if($f) $this->lockInputfield($f);
		}
	}
	
	/**
	 * Hook after ProcessPageEdit::buildFormSettings
	 *
	 * Add the _pelf setting Inputfield
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookBuildFormSettings(HookEvent $event) {

		$sanitizer = $this->wire()->sanitizer;
		$modules = $this->wire()->modules;
		$process = $event->object; /** @var WirePageEditor $process */
		$wrapper = $event->return; /** @var InputfieldWrapper $wrapper */
		$page = $process->getPage();
		$locks = $this->getLocks($page);
		
		if(!$this->hasPermission($page)) return;

		$propertyOptions = [];
		$fieldOptions = [];
		
		$labels = [
			'name' => $this->_('Page name'), 
			'template' => $this->_('Template'), 
			'parent_id' => $this->_('Parent page'), 
			'status' => $this->_('Status'), 
			'delete_page' => $this->_('Delete/trash page'), 
			'property' => $sanitizer->entities1($this->_('Property')),
		];
		
		$skipTypes = [
			'FieldsetOpen',
			'FieldsetTabOpen',
			'FieldsetClose',
		];

		$f = $wrapper->InputfieldCheckboxes;
		$f->attr('id+name', self::name);
		$f->label = $this->_('Locked fields');
		$f->icon = 'lock';
		$f->collapsed = Inputfield::collapsedYes;
		$f->table = true;
		$f->description = $this->_('Checked fields are locked.');
		$f->thead = 
			$this->_('Name') . '|' . 
			$this->_('Label') . '|' . 
			$this->_('Type'); 

		foreach($this->lockProperties as $name) {
			$label = isset($labels[$name]) ? $labels[$name] : '';
			$label = $sanitizer->entities1($label);
			$propertyOptions[$name] = "$name|$label|$labels[property]";
		}

		foreach($page->template->fieldgroup as $field) {
			/** @var Field $field */
			$label = $sanitizer->entities1($field->label);
			$type = $field->type->shortName;
			if(in_array($type, $skipTypes)) continue;
			$fieldOptions[$field->name] = "$field->name|$label|$type";
		}
		
		asort($fieldOptions);

		$f->addOptions($propertyOptions);
		$f->addOptions($fieldOptions);

		$f->val(array_values($locks));
		$f->resetTrackChanges();

		/** @var JqueryUI $jQueryUI */
		$jQueryUI = $modules->get('JqueryUI');
		$jQueryUI->use('vex');
		
		$useLongclick = $this->hasToggle('longclick');
	
		if($useLongclick) {
			/** @var JqueryCore $jQueryCore */
			$jQueryCore = $modules->get('JqueryCore');
			$jQueryCore->use('longclick');
		}
	
		$config = $this->wire()->config;
		$config->scripts->add($config->urls($this) . $this->className() . '.js');
		if(count($this->renderInputsFor)) {
			$config->styles->add($config->urls($this) . $this->className() . '.css');
		}
		$config->js($this->className, [
			'useLongclick' => $useLongclick,
			'useLockalert' => $this->hasToggle('lockalert'),
			'useMinimize' => $this->hasToggle('minimize'),
			'jumpToLocks' => $this->wire()->input->get('pelf') !== null, 
			'renderInputsFor' => $this->renderInputsFor,
			'lockLabel' => $this->_('Lock this field?'),
			'lockedLabel' => $this->lockedLabel(),
			'unlockLabel' => $this->_('Unlock this field?'),
			'notLockableLabel' => $this->_('This field not lockable.'), 
			'lockDesc' => $this->_('This field will become locked after you save.'),
			'unlockDesc' => $this->_('This field will be unlocked after you save.'),
		]);

		$wrapper->add($f);
	}


	/**
	 * Hook after ProcessPageEdit::processInput
	 *
	 * Process the _pelf setting
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookBeforeProcessInput(HookEvent $event) {
		
		$level = (int) $event->arguments(1);
		if($level > 0) return;
	
		$input = $this->wire()->input;
		$form = $event->arguments(0); /** @var InputfieldForm $form */
		$process = $event->object; /** @var ProcessPageEdit $process */
		$page = $process->getPage();
		$locks = $this->getLocks($page);
		$fieldNames = array_values($locks);

		$f = $form->getChildByName(self::name); /** @var Inputfield $f */
		if(!$f) return;
		
		$f->processInput($input->post);
		
		if($f->isChanged() && $this->hasPermission($page)) { 
			$value = $f->val();
			if($value != $fieldNames) {
				$this->removeAllLocksForPage($page);
				$this->addLocks($page, $value);
			} else {
				$f->resetTrackChanges();
			}
		}
		
		$f->getParent()->remove($f);
	}

	/*****************************************************************
	 * PUBLIC API
	 * 
	 */
	
	/**
	 * Get all locks for given page
	 *
	 * @param Page|int $page
	 * @return array Returned array is [ id => fieldName ]
	 * @throws WireException
	 *
	 */
	public function getLocks($page) {
		$pageId = $this->pageId($page);
		if(isset($this->locksCache[$pageId])) return $this->locksCache[$pageId];
		$fieldNames = [];
		$database = $this->wire()->database;
		$table = self::table;
		$sql = "SELECT * FROM $table WHERE pages_id=:pid";
		$query = $database->prepare($sql);
		$query->bindValue(':pid', $pageId, \PDO::PARAM_INT);
		$query->execute();
		$this->locksFlags[$pageId] = [];
		while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
			$fieldId = (int) $row['fields_id'];
			$fieldNames[$fieldId] = $this->fieldName($fieldId);
			$this->locksFlags[$pageId][$fieldId] = (int) $row['flags'];
		}
		$query->closeCursor();
		$this->locksCache[$pageId] = $fieldNames;
		return $fieldNames;
	}

	/**
	 * Get array containing all pages with locks and what is locked
	 * 
	 * @return array
	 * 
	 */
	public function getAllLocks() {
	
		$pages = $this->wire()->pages;
		$database = $this->wire()->database;
		$table = self::table;
		$sql = "SELECT * FROM $table ORDER BY pages_id";
		$query = $database->prepare($sql);
		$query->execute();
		$items = [];
		
		while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
			$pid = (int) $row['pages_id'];
			$fid = (int) $row['fields_id'];
			if(!isset($items[$pid])) {
				$path = $pages->getPath($pid);
				$items[$pid] = [
					'id' => $pid,
					'path' => $path,
					'fields' => [], 
				];
			}
			$fieldName = $this->fieldName($fid);
			$items[$pid]['fields'][$fid] = $fieldName;
		}
		
		$query->closeCursor();
		
		return $items;
	}

	/**
	 * Is page field locked?
	 *
	 * @param Page|int $page
	 * @param Field|int|string $field
	 * @return bool
	 *
	 */
	public function isLocked($page, $field) {
		$pageId = $this->pageId($page);
		$fieldId = $this->fieldId($field);
		if(!isset($this->locksCache[$pageId])) $this->getLocks($page);
		return isset($this->locksCache[$pageId][$fieldId]);
	}

	/**
	 * Add lock for given page and field
	 * 
	 * @param Page|int $page
	 * @param Field|string|int $field
	 * @return bool
	 * 
	 */	
	public function addLock($page, $field, $flags = 0) {
		$database = $this->wire()->database;
		$table = self::table;
		$pageId = $this->pageId($page);
		$sql = "INSERT INTO $table VALUES(:pid, :fid, :flags)";
		$query = $database->prepare($sql);
		$query->bindValue(':pid', $pageId, \PDO::PARAM_INT);
		$query->bindValue(':fid', $this->fieldId($field), \PDO::PARAM_INT);
		$query->bindValue(':flags', (int) $flags, \PDO::PARAM_INT);
		try {
			$result = $query->execute();
		} catch(\Exception $e) {
			$this->error($e->getMessage());
			$result = false;
		}
		$this->resetCache($page);
		return $result;
	}

	/**
	 * Add lock for given page and fields (plural)
	 * 
	 * Note: does not add lock flags
	 *
	 * @param Page|int $page
	 * @param Field[]|string[]|int[] $fields
	 * @return int Quantity of locks added
	 *
	 */
	public function addLocks($page, array $fields) {
		$database = $this->wire()->database;
		$table = self::table;
		$pageId = $this->pageId($page);
		$sql = "INSERT INTO $table VALUES(:pid, :fid, 0)";
		$query = $database->prepare($sql);
		$query->bindValue(':pid', $pageId, \PDO::PARAM_INT);
		$qty = 0;
		foreach($fields as $field) {
			$fieldId = $this->fieldId($field);
			$query->bindValue(':fid', $fieldId, \PDO::PARAM_INT);
			try {
				if($query->execute()) $qty++;
			} catch(\Exception $e) {
				// duplicate, ignore
			}
		}
		$this->resetCache($page);
		return $qty;
	}

	/**
	 * Remove lock for given page and field
	 *
	 * @param Page|int $page
	 * @param Field|string|int $field
	 * @return bool
	 *
	 */
	public function removeLock($page, $field) {
		$database = $this->wire()->database;
		$table = self::table;
		$pageId = $this->pageId($page);
		$sql = "DELETE FROM $table WHERE pages_id=:pid AND fields_id=:fid";
		$query = $database->prepare($sql);
		$query->bindValue(':pid', $pageId);
		$query->bindValue(':fid', $this->fieldId($field));
		$query->execute();
		$this->resetCache($pageId);
		return (bool) $query->rowCount();
	}

	/**
	 * Remove all field locks for given page
	 * 
	 * @param Page|int $page
	 * @return int
	 * @throws WireException
	 * 
	 */
	public function removeAllLocksForPage($page) {
		$database = $this->wire()->database;
		$table = self::table;
		$pageId = $this->pageId($page);
		$sql = "DELETE FROM $table WHERE pages_id=:pid";
		$query = $database->prepare($sql);
		$query->bindValue(':pid', $pageId);
		$query->execute();
		$this->resetCache($pageId);
		return $query->rowCount();
	}
	
	/**
	 * Remove all field locks for given field
	 *
	 * @param Field|string|int $field
	 * @return int
	 *
	 */
	public function removeAllLocksForField($field) {
		$database = $this->wire()->database;
		$table = self::table;
		$fieldId = $this->fieldId($field);
		$sql = "DELETE FROM $table WHERE fields_id=:fid";
		$query = $database->prepare($sql);
		$query->bindValue(':fid', $fieldId);
		$query->execute();
		$this->resetCache();
		return $query->rowCount();
	}

	/**
	 * Remove all locks for a field in a fieldgroup
	 * 
	 * @param Field $field
	 * @param Fieldgroup $fieldgroup
	 * @return int Number of locks removed
	 * 
	 */
	public function removeAllLocksForFieldInFieldgroup(Field $field, Fieldgroup $fieldgroup) {
		$table = self::table;
		$fieldgroupTemplates = $fieldgroup->getTemplates();
		$rows = [];
		$sql =
			"SELECT $table.pages_id, $table.fields_id FROM $table " .
			"JOIN pages ON $table.pages_id=pages.id AND pages.templates_id=:tid " .
			"WHERE $table.fields_id=:fid";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':fid', $field->id, \PDO::PARAM_INT);
		foreach($fieldgroupTemplates as $template) {
			$query->bindValue(':tid', $template->id, \PDO::PARAM_INT);
			$query->execute();
			while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
				$rows[] = $row;
			}
		}
		$query->closeCursor();
		if(!count($rows)) return 0;
		$sql = "DELETE FROM $table WHERE pages_id=:pid AND fields_id=:fid";
		$query = $this->wire()->database->prepare($sql);
		$numRows = 0;
		foreach($rows as $row) {
			$query->bindValue(':pid', $row['pages_id'], \PDO::PARAM_INT);
			$query->bindValue(':fid', $row['fields_id'], \PDO::PARAM_INT);
			$query->execute();
			$numRows += $query->rowCount();
		}
		$this->resetCache();
		return $numRows;
	}

	/**
	 * Get lock flags for given page and field (for future use)
	 * 
	 * @param Page|int $page
	 * @param Field|int|string $field
	 * @return int
	 * 
	 */
	public function getLockFlags($page, $field) {
		$pageId = $this->pageId($page);
		$fieldId = $this->fieldId($field);
		if(!isset($this->locksFlags[$pageId][$fieldId])) {
			$this->getLocks($pageId);
			if(!isset($this->locksFlags[$pageId][$fieldId])) return 0;
		}
		return $this->locksFlags[$pageId][$fieldId]; 
	}
	
	/*****************************************************************
	 * RUNTIME UTILITIES
	 *
	 */

	/**
	 * Does user have permission to lock/unlock fields? 
	 * 
	 * @param Page|null $page
	 * @param User|null $user
	 * @return bool
	 * 
	 */
	protected function hasPermission(Page $page = null, User $user = null) {
		if($user === null) $user = $this->wire()->user;	
		$lockUsers = $this->lockUsers;
		$has = false;
		if(empty($lockUsers)) {
			$has = $user->hasPermission('page-lock', $page);
		} else {
			if(is_string($lockUsers)) $lockUsers = explode(' ', $lockUsers);
			$userId = (int) $user->id;
			foreach($lockUsers as $lockUserId) {
				if($userId !== (int) $lockUserId) continue;
				$has = true;
				break;
			}
		}
		return $has;
	}

	/**
	 * Adjust Inputfield collapsed state to be locked
	 * 
	 * @param Inputfield $f
	 * 
	 */
	protected function lockInputfield(Inputfield $f) {

		// true if Inputfield should use render() rather than renderValue() 
		$renderInputs = false;
	
		if(empty($_POST) && count($this->renderInputsFor)) {
			$fieldId = $f->hasField ? $f->hasField->id : $this->fieldId($f->attr('name'));
			$renderInputs = in_array($fieldId, $this->renderInputsFor);
			if($renderInputs) $f->wrapClass('InputfieldIsLockedButRendered');
		}
		
		if($f->collapsed == Inputfield::collapsedYesAjax) {
			$this->lockAjaxInputfield($f);
		} else if($this->hasToggle('minimize')) {
			$f->collapsed = $renderInputs ? Inputfield::collapsedYes : Inputfield::collapsedYesLocked;
		} else if($renderInputs) {
			// leave as-is
		} else if(isset($this->lockAdjustments[$f->collapsed])) {
			$f->collapsed = $this->lockAdjustments[$f->collapsed];
		} else {
			$f->collapsed = Inputfield::collapsedNoLocked;
		}
		
		$f->wrapClass('InputfieldIsLocked InputfieldIsLockedAtStart');
		
		if($this->hasToggle('locknote')) {
			$note = $this->wire()->sanitizer->entities1($this->lockedLabel());
			$icon = wireIconMarkup('lock');
			$f->prependMarkup .=
				"<p class='pwlf-locknote'><span class='notes'>$icon $note</span></p>";
		}
	}

	/**
	 * Lock an ajax inputfield (must be called from lockInputfield method)
	 * 
	 * This is part of lockInputfield method, and adds extra logic necessary 
	 * to lock an ajax inputfield. This is necessary since there is currently
	 * no combination locked + ajax collapsed state in the core. 
	 * 
	 * @param Inputfield $f
	 * @param bool $renderInputs
	 * 
	 */
	protected function lockAjaxInputfield(Inputfield $f, $renderInputs) {
		if(!$renderInputs && $this->wire()->input->get('renderInputfieldAjax') === $f->attr('id')) {
			$this->addHookBefore('InputfieldWrapper::renderInputfield', 
				function(HookEvent $e) use($f) {
					$inputfield = $e->arguments(0); /** @var Inputfield $inputfield */
					if($inputfield !== $f) return;
					$e->arguments(1, true); // argument 1: renderValueMode=true
				}
			); 
		}
		$f->addHookBefore('processInput', function(HookEvent $e) {
			// prevent processing of locked ajax inputfield
			$e->replace = true;
			$e->return = $e->object;
		});
	}

	/**
	 * @return string
	 * 
	 */
	protected function lockedLabel() {
		return $this->_('This field is locked and may not be modified.');
	}

	/**
	 * Reset/clear cache for given page
	 * 
	 * @param Page|int $page
	 * 
	 */
	protected function resetCache($page = null) {
		if($page) {
			$pageId = $this->pageId($page);
			unset($this->locksCache[$pageId]);
			unset($this->locksFlags[$pageId]);
		} else {
			$this->locksCache = [];
			$this->locksFlags = [];
		}
	}

	/**
	 * Given name or Field object (or id), return id or 0 if not found
	 * 
	 * @param Field|int|string $field
	 * @return int Negative number refers to page property rather than Field
	 * 
	 */
	protected function fieldId($field) {
		if($field instanceof Field) return $field->id;
		if(ctype_digit("$field")) return (int) $field;
		if(isset($this->lockProperties[$field])) return (int) $field;
		if(isset($this->altPropertyNames[$field])) $field = $this->altPropertyNames[$field];
		$key = array_search($field, $this->lockProperties);
		if($key) return $key;
		if(is_string($field)) {
			$field = $this->wire()->fields->get($field);
			if($field) return $field->id;
		}
		return 0;
	}

	/**
	 * Given id, Field (or name), return field name or blank if not found
	 * 
	 * @param int Field|string|$id
	 * @return string
	 * 
	 */
	protected function fieldName($id) {
		if($id instanceof Field) return $id->name;
		if(strpos("$id", '-') === 0) {
			$id = (int) $id;
			if(isset($this->lockProperties[$id])) return $this->lockProperties[$id];
		} else if(ctype_digit("$id")) {
			$field = $this->wire()->fields->get($id);
			return $field ? $field->name : '';
		} else if(isset($this->altPropertyNames[$id])) {
			return $this->altPropertyNames[$id];
		}
		if(is_string($id)) return $id;
		return '';
	}

	/**
	 * Given field/property name, return what its name is in an InputfieldForm
	 * 
	 * @param string $fieldName
	 * @return string
	 * 
	 */
	protected function formFieldName($fieldName) {
		if($fieldName === 'name') return '_pw_page_name';
		return $fieldName;
	}

	/**
	 * Given Page or id return id or 0 if not found
	 * 
	 * @param Page|int $page
	 * @return int
	 * 
	 */
	protected function pageId($page) {
		if(is_int($page)) return $page;
		if($page instanceof Page) return $page->id;
		if(ctype_digit("$page")) return (int) $page;
		return 0;
	}

	/**
	 * Is the named toggle/feature enabled?
	 * 
	 * @param string $name
	 * @return bool
	 * 
	 */
	protected function hasToggle($name) {
		return in_array($name, $this->toggles);
	}

	/**
	 * @return string[]
	 * 
	 */
	public function lockProperties() {
		return $this->lockProperties;
	}

	/**
	 * Install
	 * 
	 */
	public function install() {
		$sql = 
			'CREATE TABLE ' . self::table . ' (' . 
				'pages_id INT UNSIGNED NOT NULL, ' . 
				'fields_id INT SIGNED NOT NULL, ' .
				'flags INT UNSIGNED NOT NULL DEFAULT 0, ' . 
				'PRIMARY KEY(pages_id, fields_id) ' . 
			') ENGINE=InnoDB';
		$this->wire()->database->exec($sql);
	}

	/**
	 * Uninstall
	 *
	 */
	public function uninstall() {
		$sql = 'DROP TABLE ' . self::table;
		$this->wire()->database->exec($sql);
	}

	/**
	 * Config
	 * 
	 * @param InputfieldWrapper $inputfields
	 * 
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		require_once(__DIR__ . '/config.php');
		PageEditLockFieldsConfig($inputfields, $this);
	}
}