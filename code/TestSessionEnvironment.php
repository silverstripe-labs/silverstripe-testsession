<?php

/**
 * Class TestSessionEnvironment
 * Abstracts out how testing sessions are started, run, and finished. This should ensure that test sessions details are
 * enforced across multiple separate requests (for example: behat CLI starts a testsession, then opens a web browser -
 * the web browser should know nothing about the test session, and shouldn't need to visit dev/testsession/start itself
 * as it will be loaded from this class). Additionally, Resque workers etc. should also not need to know about it
 * (although in that case they do need to poll for changes to testsession, as they are a long-lived process that is
 * generally started much earlier than the test session is created).
 *
 * Information here is currently stored on the filesystem - in the webroot, as it's the only persistent place to store
 * this detail.
 */
class TestSessionEnvironment extends Object {
	/**
	 * @var stdClass Test session state. For a valid test session to exist, this needs to contain at least:
	 *     - database: The alternate database name that is being used for this test session (e.g. ss_tmpdb_1234567)
	 * It can optionally contain other details that should be passed through many separate requests:
	 *     - datetime: Mocked SS_DateTime ({@see TestSessionRequestFilter})
	 *     - mailer: Mocked Email sender ({@see TestSessionRequestFilter})
	 *     - stubfile: Path to PHP stub file for setup ({@see TestSessionRequestFilter})
	 * Extensions of TestSessionEnvironment can add extra fields in here to be saved and restored on each request.
	 */
	private $state;

	/**
	 * @var string The original database name, before we overrode it with our tmpdb.
	 *
	 * Used in {@link self::resetDatabaseName()} when we want to restore the normal DB connection.
	 */
	private $oldDatabaseName;

	/**
	 * @config
	 * @var string Path (from web-root) to the test state file that indicates a testsession is in progress.
	 * Defaults to value stored in testsession/_config/_config.yml
	 */
	private static $test_state_file;

	/**
	 * @var TestSessionEnvironment A singleton of this TestSessionEnvironment, for use with ::inst()
	 */
	private static $instance = null;

	public static function inst() {
		if (!self::$instance) {
			self::$instance = new TestSessionEnvironment();
		}
		return self::$instance;
	}

	/**
	 * Tests for the existence of the file specified by $this->test_state_file
	 */
	public function isRunningTests() {
		return(file_exists(Director::getAbsFile($this->config()->test_state_file)));
	}

	/**
	 * Creates a temp database, sets up any extra requirements, and writes the state file. The database will be
	 * connected to as part of {@link self::applyState()}, so if you're continuing script execution after calling this
	 * method, be aware that the database will be different - so various things may break (e.g. administrator logins
	 * using the SS_DEFAULT_USERNAME / SS_DEFAULT_PASSWORD constants).
	 *
	 * If something isn't explicitly handled here, and needs special handling, then it should be taken care of by an
	 * extension to TestSessionEnvironment. You can either extend onBeforeStartTestSession() or
	 * onAfterStartTestSession(). Alternatively, for more fine-grained control, you can also extend
	 * onBeforeApplyState() and onAfterApplyState(). See the {@link self::applyState()} method for more.
	 *
	 * @param array $state An array of test state options to write.
	 */
	public function startTestSession($state) {
		$this->extend('onBeforeStartTestSession', $state);

		// Convert to JSON and back so we can share the appleState() code between this and ->loadFromFile()
		$jason = json_encode($state, JSON_FORCE_OBJECT);
		$state = json_decode($jason);

		$this->applyState($state);
		$this->persistState();

		$this->extend('onAfterStartTestSession');
	}

	public function updateTestSession($state) {
		$this->extend('onBeforeUpdateTestSession', $state);

		// Convert to JSON and back so we can share the appleState() code between this and ->loadFromFile()
		$jason = json_encode($state, JSON_FORCE_OBJECT);
		$state = json_decode($jason);

		$this->applyState($state);
		$this->persistState();

		$this->extend('onAfterUpdateTestSession');
	}

	/**
	 * Assumes the database has already been created in startTestSession(), as this method can be called from
	 * _config.php where we don't yet have a DB connection.
	 *
	 * Does not persist the state to the filesystem, {@see self::persistState()}.
	 *
	 * You can extend this by creating an Extension object and implementing either onBeforeApplyState() or
	 * onAfterApplyState() to add your own test state handling in.
	 *
	 * @throws LogicException
	 * @throws InvalidArgumentException
	 */
	public function applyState($state) {
		global $databaseConfig;

		$this->extend('onBeforeApplyState', $state);

		// Load existing state from $this->state into $state, if there is any
		if($this->state) {
			foreach($this->state as $k => $v) {
				if(!isset($state->$k)) $state->$k = $v; // Don't overwrite stuff in $state, as that's the new state
			}
		}

		if(!DB::getConn()) {
			// No connection, so try and connect to tmpdb if it exists
			if(isset($state->database)) {
				$this->oldDatabaseName = $databaseConfig['database'];
				$databaseConfig['database'] = $state->database;
			}

			// Connect to database
			DB::connect($databaseConfig);
		} else {
			// We've already connected to the database, do a fast check to see what database we're currently using
			$db = DB::query("SELECT DATABASE()")->value();
			if(isset($state->database) && $db != $state->database) {
				$this->oldDatabaseName = $databaseConfig['database'];
				$databaseConfig['database'] = $state->database;
				DB::connect($databaseConfig);
			}
		}

		// Database
		if(!$this->isRunningTests() && (@$state->createDatabase || @$state->database)) {
			$dbName = (isset($state->database)) ? $state->database : null;

			if($dbName) {
				$dbExists = (bool)DB::query(
					sprintf("SHOW DATABASES LIKE '%s'", Convert::raw2sql($dbName))
				)->value();
			} else {
				$dbExists = false;
			}

			if(!$dbExists) {
				// Create a new one with a randomized name
				$dbName = SapphireTest::create_temp_db();

				$state->database = $dbName; // In case it's changed by the call to SapphireTest::create_temp_db();

				// Set existing one, assumes it already has been created
				$prefix = defined('SS_DATABASE_PREFIX') ? SS_DATABASE_PREFIX : 'ss_';
				$pattern = strtolower(sprintf('#^%stmpdb\d{7}#', $prefix));
				if(!preg_match($pattern, $dbName)) {
					throw new InvalidArgumentException("Invalid database name format");
				}

				$this->oldDatabaseName = $databaseConfig['database'];
				$databaseConfig['database'] = $dbName; // Instead of calling DB::set_alternative_db_name();

				// Connect to the new database, overwriting the old DB connection (if any)
				DB::connect($databaseConfig);
			}

			// Import database template if required
			if(isset($state->createDatabaseTemplate) && $state->createDatabaseTemplate) {
				$sql = file_get_contents($state->createDatabaseTemplate);
				// Split into individual query commands, removing comments
				$sqlCmds = array_filter(
					preg_split('/\s*;\s*/',
						preg_replace(array('/^$\n/m', '/^(\/|#).*$\n/m'), '', $sql)
					)
				);

				// Execute each query
				foreach($sqlCmds as $sqlCmd) {
					DB::query($sqlCmd);
				}

				// In case the dump involved CREATE TABLE commands, we need to ensure
				// the schema is still up to date
				$dbAdmin = new DatabaseAdmin();
				$dbAdmin->doBuild(true /*quiet*/, false /*populate*/);
			}

			if(isset($state->createDatabase)) unset($state->createDatabase);
		}

		// Fixtures
		$fixtureFile = (isset($state->fixture)) ? $state->fixture : null;
		if($fixtureFile) {
			$this->loadFixtureIntoDb($fixtureFile);
			unset($state->fixture); // Only insert the fixture(s) once, not every time we call this method
		}

		// Mailer
		$mailer = (isset($state->mailer)) ? $state->mailer : null;
		if($mailer) {
			if(!class_exists($mailer) || !is_subclass_of($mailer, 'Mailer')) {
				throw new InvalidArgumentException(sprintf(
					'Class "%s" is not a valid class, or subclass of Mailer',
					$mailer
				));
			}
		}

		// Date and time
		if(isset($state->datetime)) {
			require_once 'Zend/Date.php';
			// Convert DatetimeField format
			if(!Zend_Date::isDate($state->datetime, 'yyyy-MM-dd HH:mm:ss')) {
				throw new LogicException(sprintf(
					'Invalid date format "%s", use yyyy-MM-dd HH:mm:ss',
					$state->datetime
				));
			}
		}

		$this->state = $state;

		$this->extend('onAfterApplyState');
	}

	public function loadFromFile() {
		if($this->isRunningTests()) {
			try {
				$contents = file_get_contents(Director::getAbsFile($this->config()->test_state_file));
				$jason = json_decode($contents);

				if(!isset($jason->database)) {
					throw new \LogicException('The test session file ('
						. Director::getAbsFile($this->config()->test_state_file) . ') doesn\'t contain a database name.');
				}

				$this->applyState($jason);
			} catch(Exception $e) {
				throw new \Exception("A test session appears to be in progress, but we can't retrieve the details. "
					. "Try removing the " . Director::getAbsFile($this->config()->test_state_file) . " file. Inner "
					. "error: " . $e->getMessage());
			}
		}
	}

	/**
	 * Writes $this->state JSON object into the $this->config()->test_state_file file.
	 */
	private function persistState() {
		file_put_contents(Director::getAbsFile($this->config()->test_state_file), json_encode($this->state));
	}

	private function removeStateFile() {
		if(file_exists(Director::getAbsFile($this->config()->test_state_file))) {
			if(!unlink(Director::getAbsFile($this->config()->test_state_file))) {
				throw new \Exception('Unable to remove the testsession state file, please remove it manually. File '
					. 'path: ' . Director::getAbsFile($this->config()->test_state_file));
			}
		}
	}

	/**
	 * Cleans up the test session state by restoring the normal database connect (for the rest of this request, if any)
	 * and removes the {@link self::$test_state_file} so that future requests don't use this test state.
	 *
	 * Can be extended by implementing either onBeforeEndTestSession() or onAfterEndTestSession().
	 *
	 * This should implement itself cleanly in case it is called twice (e.g. don't throw errors when the state file
	 * doesn't exist anymore because it's already been cleaned up etc.) This is because during behat test runs where
	 * a queueing system (e.g. silverstripe-resque) is used, the behat module may call this first, and then the forked
	 * worker will call it as well - but there is only one state file that is created.
	 */
	public function endTestSession() {
		$this->extend('onBeforeEndTestSession');

		if(SapphireTest::using_temp_db()) {
			$this->resetDatabaseName();
		}

		$this->removeStateFile();

		$this->extend('onAfterEndTestSession');
	}

	/**
	 * Loads a YAML fixture into the database as part of the {@link TestSessionController}.
	 *
	 * @param string $fixtureFile The .yml file to load
	 * @return FixtureFactory The loaded fixture
	 * @throws LogicException
	 */
	protected function loadFixtureIntoDb($fixtureFile) {
		$realFile = realpath(BASE_PATH.'/'.$fixtureFile);
		$baseDir = realpath(Director::baseFolder());
		if(!$realFile || !file_exists($realFile)) {
			throw new LogicException("Fixture file doesn't exist");
		} else if(substr($realFile,0,strlen($baseDir)) != $baseDir) {
			throw new LogicException("Fixture file must be inside $baseDir");
		} else if(substr($realFile,-4) != '.yml') {
			throw new LogicException("Fixture file must be a .yml file");
		} else if(!preg_match('/^([^\/.][^\/]+)\/tests\//', $fixtureFile)) {
			throw new LogicException("Fixture file must be inside the tests subfolder of one of your modules.");
		}

		$factory = Injector::inst()->create('FixtureFactory');
		$fixture = Injector::inst()->create('YamlFixture', $fixtureFile);
		$fixture->writeInto($factory);

		$this->state->fixtures[] = $fixtureFile;

		return $fixture;
	}

	/**
	 * Reset the database connection to use the original database. Called by {@link self::endTestSession()}.
	 */
	public function resetDatabaseName() {
		global $databaseConfig;

		$databaseConfig['database'] = $this->oldDatabaseName;

		DB::connect($databaseConfig);
	}

	/**
	 * @return stdClass Data as taken from the JSON object in {@link self::loadFromFile()}
	 */
	public function getState() {
		return $this->state;
	}
}