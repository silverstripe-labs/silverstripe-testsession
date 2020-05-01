<?php

namespace SilverStripe\TestSession;

use DirectoryIterator;
use Exception;
use InvalidArgumentException;
use LogicException;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\Connect\TempDatabase;
use SilverStripe\ORM\DatabaseAdmin;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\TestSession\TestSessionState;
use SilverStripe\Versioned\Versioned;
use stdClass;

/**
 * Responsible for starting and finalizing test sessions.
 * Since these session span across multiple requests, session information is persisted
 * in a file. This file is stored in the webroot by default, and the test session
 * is considered "in progress" as long as this file exists.
 *
 * This allows for cross-request, cross-client sharing of the same testsession,
 * for example: Behat CLI starts a testsession, then opens a web browser which
 * makes a separate request picking up the same testsession.
 *
 * An environment can have an optional identifier ({@link id}), which allows
 * multiple environments to exist at the same time in the same webroot.
 * This enables parallel testing with (mostly) isolated state.
 *
 * For a valid test session to exist, this needs to contain at least:
 *  - database: The alternate database name that is being used for this test session (e.g. ss_tmpdb_1234567)
 * It can optionally contain other details that should be passed through many separate requests:
 *  - datetime: Mocked SS_DateTime ({@see TestSessionRequestFilter})
 *  - mailer: Mocked Email sender ({@see TestSessionRequestFilter})
 *  - stubfile: Path to PHP stub file for setup ({@see TestSessionRequestFilter})
 * Extensions of TestSessionEnvironment can add extra fields in here to be saved and restored on each request.
 *
 * See {@link $state} for default information stored in the test session.
 */
class TestSessionEnvironment
{
    use Injectable;
    use Configurable;
    use Extensible;

    /**
     * @var int Optional identifier for the session.
     */
    protected $id;

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
    private static $test_state_file = 'TESTS_RUNNING.json';

    /**
     * @config
     * @var [type]
     */
    private static $test_state_id_file = 'TESTS_RUNNING-%s.json';

    public function __construct($id = null)
    {
        if ($id) {
            $this->id = $id;
        }
    }

    public function init(HTTPRequest $request)
    {
        if (!$this->id) {
            $request->getSession()->init($request);
            // $_SESSION != Session::get() in some execution paths, suspect Controller->pushCurrent()
            // as part of the issue, easiest resolution is to use session directly for now
            $this->id = $request->getSession()->get('TestSessionId');
        }
    }

    /**
     * @return string Absolute path to the file persisting our state.
     */
    public function getFilePath()
    {
        if ($this->id) {
            $path = Director::getAbsFile(sprintf($this->config()->get('test_state_id_file'), $this->id));
        } else {
            $path = Director::getAbsFile($this->config()->get('test_state_file'));
        }

        return $path;
    }

    /**
     * Tests for the existence of the file specified by $this->test_state_file
     */
    public function isRunningTests()
    {
        return (file_exists($this->getFilePath()));
    }

    /**
     * @param String $id
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @return String
     */
    public function getId()
    {
        return $this->id;
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
     * @param mixed $id
     */
    public function startTestSession($state = null, $id = null)
    {
        if (!$state) {
            $state = array();
        }
        $this->removeStateFile();
        $this->id = $id;

        // Assumes state will be modified by reference
        $this->extend('onBeforeStartTestSession', $state);

        // Convert to JSON and back so we can share the applyState() code between this and ->loadFromFile()
        $json = json_encode($state, JSON_FORCE_OBJECT);
        $state = json_decode($json);

        $state = $this->createDatabase($state);
        $state = $this->applyState($state);
        $this->saveState($state);

        // Back up /assets folder
        $this->backupAssets();

        $this->extend('onAfterStartTestSession');
    }

    public function updateTestSession($state)
    {
        $this->extend('onBeforeUpdateTestSession', $state);

        // Convert to JSON and back so we can share the appleState() code between this and ->loadFromFile()
        $json = json_encode($state, JSON_FORCE_OBJECT);
        $state = json_decode($json);

        $this->applyState($state);
        $this->saveState($state);

        $this->extend('onAfterUpdateTestSession');
    }

    /**
     * Backup all assets from /assets to /assets_backup.
     * Note: Only does file move, no files ever duplicated / deleted
     */
    protected function backupAssets()
    {
        // Ensure files backed up to assets dir
        $backupFolder = $this->getAssetsBackupfolder();
        if (!is_dir($backupFolder)) {
            Filesystem::makeFolder($backupFolder);
        }
        $this->moveRecursive(ASSETS_PATH, $backupFolder, ['.htaccess', 'web.config', '.protected']);
    }

    /**
     * Restore all assets to /assets folder.
     * Note: Only does file move, no files ever duplicated / deleted
     */
    public function restoreAssets()
    {
        // Ensure files backed up to assets dir
        $backupFolder = $this->getAssetsBackupfolder();
        if (is_dir($backupFolder)) {
            // Move all files
            Filesystem::makeFolder(ASSETS_PATH);
            $this->moveRecursive($backupFolder, ASSETS_PATH);
            Filesystem::removeFolder($backupFolder);
        }
    }

    /**
     * Recursively move files from one directory to another
     *
     * @param string $src Source of files being moved
     * @param string $dest Destination of files being moved
     * @param array $ignore List of files to not move
     */
    protected function moveRecursive($src, $dest, $ignore = [])
    {
        // If source is not a directory stop processing
        if (!is_dir($src)) {
            return;
        }

        // If the destination directory does not exist create it
        if (!is_dir($dest) && !mkdir($dest)) {
            // If the destination directory could not be created stop processing
            return;
        }

        // Open the source directory to read in files
        $iterator = new DirectoryIterator($src);
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                if (!in_array($file->getFilename(), $ignore)) {
                    rename($file->getRealPath(), $dest . DIRECTORY_SEPARATOR . $file->getFilename());
                }
            } elseif (!$file->isDot() && $file->isDir()) {
                // If a dir is ignored, still move children but don't remove self
                $this->moveRecursive($file->getRealPath(), $dest . DIRECTORY_SEPARATOR . $file);
                if (!in_array($file->getFilename(), $ignore)) {
                    Filesystem::removeFolder($file->getRealPath());
                }
            }
        }
    }

    /**
     * Creates a TempDB
     * Will modify state if the created database has a different name from what was in state
     *
     * @param stdClass $state
     * @return stdClass $state
     */
    public function createDatabase($state)
    {
        $dbCreate = isset($state->createDatabase) ? (bool) $state->createDatabase : false;
        $dbName = (isset($state->database)) ? $state->database : null;

        if ($dbName) {
            $dbExists = DB::get_conn()->databaseExists($dbName);
        } else {
            $dbExists = false;
        }

        $databaseConfig = DB::getConfig();
        if (!$dbExists && $dbCreate) {
            $this->oldDatabaseName = $databaseConfig['database'];
            // Ensure we don't allow TempDatabase to immediately kill the newly created DB when the request finishes
            Config::modify()->set(TempDatabase::class, 'teardown_on_exit', false);

            // Create a new one with a randomized name
            $tempDB = TempDatabase::create();
            $dbName = $tempDB->build();

            $state->database = $dbName; // In case it's changed by the call to SapphireTest::create_temp_db();

            // Set existing one, assumes it already has been created
            $prefix = Environment::getEnv('SS_DATABASE_PREFIX') ?: 'ss_';
            $pattern = strtolower(sprintf('#^%stmpdb.*#', preg_quote($prefix, '#')));
            if (!preg_match($pattern, $dbName)) {
                throw new InvalidArgumentException("Invalid database name format");
            }
        }

        if ($dbExists || $dbCreate) {
            // Connect to the new database, overwriting the old DB connection (if any)
            $databaseConfig['database'] = $dbName; // Instead of calling DB::set_alternative_db_name();
            DB::connect($databaseConfig);
            TestSessionState::create()->write();  // initialize the session state
        }

        return $state;
    }

    /**
     * Takes a state object and applies environment transformations to current application environment
     *
     * Does not persist any changes to the the state object - @see saveState for persistence
     *
     * Assumes the database has already been created in startTestSession(), as this method can be called from
     * _config.php where we don't yet have a DB connection.
     *
     * You can extend this by creating an Extension object and implementing either onBeforeApplyState() or
     * onAfterApplyState() to add your own test state handling in.
     *
     * @param mixed $state
     * @throws LogicException
     * @throws InvalidArgumentException
     * @return mixed $state
     */
    public function applyState($state)
    {
        $this->extend('onBeforeApplyState', $state);

        // back up source
        $databaseConfig = DB::getConfig();
        $this->oldDatabaseName = $databaseConfig['database'];

        // ensure we have a connection to the database
        $this->connectToDatabase($state);

        // Mailer
        $mailer = (isset($state->mailer)) ? $state->mailer : null;

        if ($mailer) {
            if (!class_exists($mailer) || !is_subclass_of($mailer, Mailer::class)) {
                throw new InvalidArgumentException(sprintf(
                    'Class "%s" is not a valid class, or subclass of Mailer',
                    $mailer
                ));
            }
            Injector::inst()->registerService(new $mailer(), Mailer::class);
            Email::config()->set("send_all_emails_to", null);
        }

        // Date and time
        if (isset($state->datetime)) {
            $formatter = DBDatetime::singleton()->getFormatter();
            $formatter->setPattern(DBDatetime::ISO_DATETIME);
            // Convert DatetimeField format
            if ($formatter->parse($state->datetime) === false) {
                throw new LogicException(sprintf(
                    'Invalid date format "%s", use yyyy-MM-dd HH:mm:ss',
                    $state->datetime
                ));
            }
            DBDatetime::set_mock_now($state->datetime);
        }

        // Allows inclusion of a PHP file, usually with procedural commands
        // to set up required test state. The file can be generated
        // through {@link TestSessionStubCodeWriter}, and the session state
        // set through {@link TestSessionController->set()} and the
        // 'testsession.stubfile' state parameter.
        if (isset($state->stubfile)) {
            $file = $state->stubfile;
            if (!Director::isLive() && $file && file_exists($file)) {
                include_once($file);
            }
        }

        $this->extend('onAfterApplyState', $state);

        return $state;
    }

    /**
     * Import the database
     *
     * @param String $path Absolute path to a SQL dump (include DROP TABLE commands)
     * @param bool $requireDefaultRecords
     */
    public function importDatabase($path, $requireDefaultRecords = false)
    {
        $sql = file_get_contents($path);

        // Split into individual query commands, removing comments
        $sqlCmds = array_filter(preg_split(
            '/;\n/',
            preg_replace(array('/^$\n/m', '/^(\/|#).*$\n/m'), '', $sql)
        ));

        // Execute each query
        foreach ($sqlCmds as $sqlCmd) {
            DB::query($sqlCmd);
        }

        // In case the dump involved CREATE TABLE commands, we need to ensure the schema is still up to date
        $dbAdmin = new DatabaseAdmin();
        Versioned::set_reading_mode('');
        $dbAdmin->doBuild(true, $requireDefaultRecords);
    }

    /**
     * Build the database with default records, see {@link DataObject->requireDefaultRecords()}.
     */
    public function requireDefaultRecords()
    {
        $dbAdmin = new DatabaseAdmin();
        Versioned::set_reading_mode('');
        $dbAdmin->doBuild(true, true);
    }

    /**
     * Sliented as if the file already exists by another process, we don't want
     * to modify.
     *
     * @param mixed $state
     */
    public function saveState($state)
    {
        if (defined('JSON_PRETTY_PRINT')) {
            $content = json_encode($state, JSON_PRETTY_PRINT);
        } else {
            $content = json_encode($state);
        }
        $old = umask(0);
        file_put_contents($this->getFilePath(), $content, LOCK_EX);
        umask($old);
    }

    public function loadFromFile()
    {
        if ($this->isRunningTests()) {
            try {
                $contents = file_get_contents($this->getFilePath());
                $json = json_decode($contents);

                $this->applyState($json);
            } catch (Exception $e) {
                throw new Exception(
                    "A test session appears to be in progress, but we can't retrieve the details.\n"
                    . "Try removing the " . $this->getFilePath() . " file.\n"
                    . "Inner error: " . $e->getMessage() . "\n"
                    . "Stacktrace: " . $e->getTraceAsString()
                );
            }
        }
    }

    private function removeStateFile()
    {
        $file = $this->getFilePath();

        if (file_exists($file)) {
            if (!unlink($file)) {
                throw new \Exception('Unable to remove the testsession state file, please remove it manually. File '
                    . 'path: ' . $file);
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
    public function endTestSession()
    {
        $this->extend('onBeforeEndTestSession');

        // Restore assets
        $this->restoreAssets();

        // Reset DB
        $tempDB = TempDatabase::create();
        if ($tempDB->isUsed()) {
            $state = $this->getState();
            $dbConn = DB::get_schema();
            $dbExists = $dbConn->databaseExists($state->database);
            if ($dbExists) {
                // Clean up temp database
                $dbConn->dropDatabase($state->database);
                file_put_contents('php://stdout', "Deleted temp database: $state->database" . PHP_EOL);
            }
            // End test session mode
            $this->resetDatabaseName();
        }

        $this->removeStateFile();

        $this->extend('onAfterEndTestSession');
    }

    /**
     * Loads a YAML fixture into the database as part of the {@link TestSessionController}.
     *
     * Writes loaded fixture files to $state->fixtures
     *
     * @param string $fixtureFile The .yml file to load
     * @return FixtureFactory The loaded fixture
     * @throws LogicException
     */
    public function loadFixtureIntoDb($fixtureFile)
    {
        $realFile = realpath(BASE_PATH . '/' . $fixtureFile);
        $baseDir = realpath(Director::baseFolder());
        if (!$realFile || !file_exists($realFile)) {
            throw new LogicException("Fixture file doesn't exist");
        } elseif (substr($realFile, 0, strlen($baseDir)) != $baseDir) {
            throw new LogicException("Fixture file must be inside $baseDir");
        } elseif (substr($realFile, -4) != '.yml') {
            throw new LogicException("Fixture file must be a .yml file");
        } elseif (!preg_match('/^([^\/.][^\/]+)\/tests\//', $fixtureFile)) {
            throw new LogicException("Fixture file must be inside the tests subfolder of one of your modules.");
        }

        $factory = Injector::inst()->create(FixtureFactory::class);
        $fixture = Injector::inst()->create(YamlFixture::class, $fixtureFile);
        $fixture->writeInto($factory);

        $state = $this->getState();
        $state->fixtures[] = $fixtureFile;
        $this->saveState($state);

        return $fixture;
    }

    /**
     * Reset the database connection to use the original database. Called by {@link self::endTestSession()}.
     */
    public function resetDatabaseName()
    {
        if ($this->oldDatabaseName) {
            $databaseConfig = DB::getConfig();
            $databaseConfig['database'] = $this->oldDatabaseName;
            DB::setConfig($databaseConfig);

            $conn = DB::get_conn();

            if ($conn) {
                $conn->selectDatabase($this->oldDatabaseName, false, false);
            }
        }
    }

    /**
     * @return stdClass Data as taken from the JSON object in {@link self::loadFromFile()}
     */
    public function getState()
    {
        $path = Director::getAbsFile($this->getFilePath());
        return (file_exists($path)) ? json_decode(file_get_contents($path)) : new stdClass;
    }

    /**
     * Path where assets should be backed up during testing
     *
     * @return string
     */
    protected function getAssetsBackupfolder()
    {
        return PUBLIC_PATH . DIRECTORY_SEPARATOR . 'assets_backup';
    }

    /**
     * Ensure that there is a connection to the database
     *
     * @param mixed $state
     * @return void
     */
    public function connectToDatabase($state = null)
    {
        if ($state == null) {
            $state = $this->getState();
        }

        $databaseConfig = DB::getConfig();

        if (isset($state->database) && $state->database) {
            if (!DB::get_conn()) {
                // No connection, so try and connect to tmpdb if it exists
                if (isset($state->database)) {
                    $this->oldDatabaseName = $databaseConfig['database'];
                    $databaseConfig['database'] = $state->database;
                }

                // Connect to database
                DB::connect($databaseConfig);
            } else {
                // We've already connected to the database, do a fast check to see what database we're currently using
                $db = DB::get_conn()->getSelectedDatabase();
                if (isset($state->database) && $db != $state->database) {
                    $this->oldDatabaseName = $databaseConfig['database'];
                    $databaseConfig['database'] = $state->database;
                    DB::connect($databaseConfig);
                }
            }
        }
    }

    /**
     * Wait for pending requests
     *
     * @param int $await Time to wait (in ms) after the last response (to allow the browser react)
     * @param int $timeout For how long (in ms) do we wait before giving up
     *
     * @return bool Whether there are no more pending requests
     */
    public function waitForPendingRequests($await = 700, $timeout = 10000)
    {
        $timeout = TestSessionState::millitime() + $timeout;
        $interval = max(300, $await);

        do {
            $now = TestSessionState::millitime();

            if ($timeout < $now) {
                return false;
            }

            $model = TestSessionState::get()->byID(1);

            $pendingRequests = $model->PendingRequests > 0;
            $lastRequestAwait = ($model->LastResponseTimestamp + $await) > $now;

            $pending = $pendingRequests || $lastRequestAwait;
        } while ($pending && (usleep($interval * 1000) || true));

        return true;
    }
}
