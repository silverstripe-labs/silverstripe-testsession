<?php
/**
 * Requires PHP's mycrypt extension in order to set the database name as an encrypted cookie.
 */
class TestSessionController extends Controller {

	private static $allowed_actions = array(
		'index',
		'start',
		'set',
		'end',
		'clear',
		'StartForm',
		'ProgressForm',
	);

	private static $alternative_database_name = -1;

	/**
	 * @var String Absolute path to a folder containing *.sql dumps.
	 */
	private static $database_templates_path;

	public function init() {
		parent::init();

		$this->extend('init');
		
		$canAccess = (
			!Director::isLive()
			&& (Director::isDev() || Director::isTest() || Director::is_cli() || Permission::check("ADMIN"))
		);
		if(!$canAccess) return Security::permissionFailure($this);

		Requirements::javascript('framework/thirdparty/jquery/jquery.js');
		Requirements::javascript('testsession/javascript/testsession.js');
	}

	public function Link($action = null) {
		return Controller::join_links(Director::baseUrl(), 'dev/testsession', $action);
	}

	public function index() {
		if(Injector::inst()->get('TestSessionEnvironment')->isRunningTests()) {
			return $this->renderWith('TestSession_inprogress');
		} else {
			return $this->renderWith('TestSession_start');
		}
	}
	
	/**
	 * Start a test session. If you wish to extend how the test session is started (and add additional test state),
	 * then take a look at {@link TestSessionEnvironment::startTestSession()} and
	 * {@link TestSessionEnvironment::applyState()} to see the extension points.
	 */
	public function start() {
		$params = $this->request->requestVars();

		// Convert datetime from form object into a single string
		$params = $this->fixDatetimeFormField($params);

		// Remove unnecessary items of form-specific data from being saved in the test session
		$params = array_diff_key(
			$params,
			array(
				'action_set' => true,
				'action_start' => true,
				'SecurityID' => true,
				'url' => true,
				'flush' => true,
			)
		);

		Injector::inst()->get('TestSessionEnvironment')->startTestSession($params);
		
		return $this->renderWith('TestSession_inprogress');
	}

	public function StartForm() {
		$databaseTemplates = $this->getDatabaseTemplates();
		$fields = new FieldList(
			new CheckboxField('createDatabase', 'Create temporary database?', 1)
		);
		if($databaseTemplates) {
			$fields->push(
				(new DropdownField('createDatabaseTemplate', false))
					->setSource($databaseTemplates)
					->setEmptyString('Empty database')
			);
		}
		$fields->merge($this->getBaseFields());
		$form = new Form(
			$this, 
			'StartForm',
			$fields,
			new FieldList(
				new FormAction('start', 'Start Session')
			)
		);
		
		$this->extend('updateStartForm', $form);

		return $form;
	}

	/**
	 * Shows state which is allowed to be modified while a test session is in progress.
	 */
	public function ProgressForm() {
		$fields = $this->getBaseFields();
		$form = new Form(
			$this, 
			'ProgressForm',
			$fields,
			new FieldList(
				new FormAction('set', 'Set testing state')
			)
		);
		
		
		$form->setFormAction($this->Link('set'));

		$this->extend('updateProgressForm', $form);

		return $form;
	}

	protected function getBaseFields() {
		$testState = Injector::inst()->get('TestSessionEnvironment')->getState();

		$fields = new FieldList(
			(new TextField('fixture', 'Fixture YAML file path'))
				->setAttribute('placeholder', 'Example: framework/tests/security/MemberTest.yml'),
			$datetimeField = new DatetimeField('datetime', 'Custom date'),
			new HiddenField('flush', null, 1)
		);
		$datetimeField->getDateField()
			->setConfig('dateformat', 'yyyy-MM-dd')
			->setConfig('showcalendar', true)
			->setAttribute('placeholder', 'Date (yyyy-MM-dd)');
		$datetimeField->getTimeField()
			->setConfig('timeformat', 'HH:mm:ss')
			->setAttribute('placeholder', 'Time (HH:mm:ss)');
		$datetimeField->setValue((isset($testState->datetime) ? $testState->datetime : null));

		$this->extend('updateBaseFields', $fields);

		return $fields;
	}

	public function DatabaseName() {
		$db = DB::getConn();
		if(method_exists($db, 'currentDatabase')) return $db->currentDatabase();
	}

	/**
	 * Updates an in-progress {@link TestSessionEnvironment} object with new details. This could be loading in new
	 * fixtures, setting the mocked date to another value etc.
	 *
	 * @return HTMLText Rendered Template
	 * @throws LogicException
	 */
	public function set() {
		if(!Injector::inst()->get('TestSessionEnvironment')->isRunningTests()) {
			throw new LogicException("No test session in progress.");
		}

		$params = $this->request->requestVars();

		// Convert datetime from form object into a single string
		$params = $this->fixDatetimeFormField($params);

		// Remove unnecessary items of form-specific data from being saved in the test session
		$params = array_diff_key(
			$params,
			array(
				'action_set' => true,
				'action_start' => true,
				'SecurityID' => true,
				'url' => true,
				'flush' => true,
			)
		);

		Injector::inst()->get('TestSessionEnvironment')->updateTestSession($params);

		return $this->renderWith('TestSession_inprogress');
	}

	public function clear() {
		if(!Injector::inst()->get('TestSessionEnvironment')->isRunningTests()) {
			throw new LogicException("No test session in progress.");
		}

		$this->extend('onBeforeClear');

		if(SapphireTest::using_temp_db()) {
			SapphireTest::empty_temp_db();
		}
		
		if(isset($_SESSION['_testsession_codeblocks'])) {
			unset($_SESSION['_testsession_codeblocks']);
		}

		$this->extend('onAfterClear');

		return "Cleared database and test state";
	}

	/**
	 * As with {@link self::start()}, if you want to extend the functionality of this, then look at
	 * {@link TestSessionEnvironent::endTestSession()} as the extension points have moved to there now that the logic
	 * is there.
	 */
	public function end() {
		if(!Injector::inst()->get('TestSessionEnvironment')->isRunningTests()) {
			throw new LogicException("No test session in progress.");
		}

		Injector::inst()->get('TestSessionEnvironment')->endTestSession();

		return $this->renderWith('TestSession_end');
	}

	/**
	 * @return boolean
	 */
	public function isTesting() {
		return SapphireTest::using_temp_db();
	}

	public function setState($data) {
		Deprecation::notice('3.1', 'TestSessionController::setState() is no longer used, please use '
			. 'TestSessionEnvironment instead.');
	}

	/**
	 * @return ArrayList
	 */
	public function getState() {
		$stateObj = Injector::inst()->get('TestSessionEnvironment')->getState();
		$state = array();

		// Convert the stdObject of state into ArrayData
		foreach($stateObj as $k => $v) {
			$state[] = new ArrayData(array(
				'Name' => $k,
				'Value' => var_export($v, true)
			));
		}

		return new ArrayList($state);
	}

	/**
	 * Get all *.sql database files located in a specific path,
	 * keyed by their file name.
	 * 
	 * @param  String $path Absolute folder path
	 * @return array
	 */
	protected function getDatabaseTemplates($path = null) {
		$templates = array();
		
		if(!$path) {
			$path = $this->config()->database_templates_path;
		}
		
		// TODO Remove once we can set BASE_PATH through the config layer
		if($path && !Director::is_absolute($path)) {
			$path = BASE_PATH . '/' . $path;
		}

		if($path && file_exists($path)) {
			$it = new FilesystemIterator($path);
			foreach($it as $fileinfo) {
				if($fileinfo->getExtension() != 'sql') continue;
				$templates[$fileinfo->getRealPath()] = $fileinfo->getFilename();
			}
		}

		return $templates;
	}

	/**
	 * @param $params array The form fields as passed through from ->start() or ->set()
	 * @return array The form fields, after fixing the datetime field if necessary
	 */
	private function fixDatetimeFormField($params) {
		if(isset($params['datetime']) && is_array($params['datetime']) && !empty($params['datetime']['date'])) {
			// Convert DatetimeField format from array into string
			$datetime = $params['datetime']['date'];
			$datetime .= ' ';
			$datetime .= (@$params['datetime']['time']) ? $params['datetime']['time'] : '00:00:00';
			$params['datetime'] = $datetime;
		} else if(isset($params['datetime']) && empty($params['datetime']['date'])) {
			unset($params['datetime']); // No datetime, so remove the param entirely
		}

		return $params;
	}

}