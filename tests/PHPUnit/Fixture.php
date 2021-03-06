<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
use Piwik\Access;
use Piwik\Common;
use Piwik\Config;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\DataTable\Manager as DataTableManager;
use Piwik\Date;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Log;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Plugins\LanguagesManager\API as APILanguageManager;
use Piwik\Plugins\MobileMessaging\MobileMessaging;
use Piwik\Plugins\ScheduledReports\API as APIScheduledReports;
use Piwik\Plugins\ScheduledReports\ScheduledReports;
use Piwik\Plugins\SitesManager\API as APISitesManager;
use Piwik\Plugins\UserCountry\LocationProvider;
use Piwik\Plugins\UsersManager\API as APIUsersManager;
use Piwik\Plugins\UsersManager\UsersManager;
use Piwik\ReportRenderer;
use Piwik\Site;
use Piwik\Tracker\Cache;
use Piwik\Translate;
use Piwik\Url;

/**
 * Base type for all integration test fixtures. Integration test fixtures
 * add visit and related data to the database before a test is run. Different
 * tests can use the same fixtures.
 *
 * This class defines a set of helper methods for fixture types. The helper
 * methods are public, but ideally they should only be used by fixture types.
 *
 * NOTE: YOU SHOULD NOT CREATE A NEW FIXTURE UNLESS THERE IS NO WAY TO MODIFY
 * AN EXISTING FIXTURE TO HANDLE YOUR USE CASE.
 *
 * Related TODO: we should try and reduce the amount of existing fixtures by
 *                merging some together.
 */
class Fixture extends PHPUnit_Framework_Assert
{
    const IMAGES_GENERATED_ONLY_FOR_OS = 'linux';
    const IMAGES_GENERATED_FOR_PHP = '5.5';
    const IMAGES_GENERATED_FOR_GD = '2.1.1';
    const DEFAULT_SITE_NAME = 'Piwik test';

    const ADMIN_USER_LOGIN = 'superUserLogin';
    const ADMIN_USER_PASSWORD = 'superUserPass';

    public $dbName = false;
    public $createConfig = true;
    public $dropDatabaseInSetUp = true;
    public $dropDatabaseInTearDown = true;
    public $loadTranslations = true;
    public $createSuperUser = true;
    public $removeExistingSuperUser = true;
    public $overwriteExisting = true;
    public $configureComponents = true;
    public $persistFixtureData = false;
    public $resetPersistedFixture = false;
    public $printToScreen = false;

    public $testEnvironment = null;

    /**
     * @return string
     */
    protected static function getPythonBinary()
    {
        if(\Piwik\SettingsServer::isWindows()) {
            return "C:\Python27\python.exe";
        }
        if(IntegrationTestCase::isTravisCI()) {
            return 'python2.6';
        }
        return 'python';
    }

    /** Adds data to Piwik. Creates sites, tracks visits, imports log files, etc. */
    public function setUp()
    {
        // empty
    }

    /** Does any clean up. Most of the time there will be no need to clean up. */
    public function tearDown()
    {
        // empty
    }

    public function getDbName()
    {
        if ($this->dbName !== false) {
            return $this->dbName;
        }

        if ($this->persistFixtureData) {
            return str_replace("\\", "_", get_class($this));
        }

        return Config::getInstance()->database_tests['dbname'];
    }

    public function performSetUp($setupEnvironmentOnly = false)
    {
        try {
            if ($this->createConfig) {
                Config::getInstance()->setTestEnvironment();
            }

            $this->dbName = $this->getDbName();

            if ($this->persistFixtureData) {
                $this->dropDatabaseInSetUp = false;
                $this->dropDatabaseInTearDown = false;
                $this->overwriteExisting = false;
                $this->removeExistingSuperUser = false;

                Config::getInstance()->database_tests['dbname'] = Config::getInstance()->database['dbname'] = $this->dbName;

                $this->getTestEnvironment()->dbName = $this->dbName;
            }

            if ($this->dbName === false) { // must be after test config is created
                $this->dbName = Config::getInstance()->database['dbname'];
            }

            static::connectWithoutDatabase();

            if ($this->dropDatabaseInSetUp
                || $this->resetPersistedFixture
            ) {
                $this->dropDatabase();
            }

            DbHelper::createDatabase($this->dbName);
            DbHelper::disconnectDatabase();

            // reconnect once we're sure the database exists
            Config::getInstance()->database['dbname'] = $this->dbName;
            Db::createDatabaseObject();

            DbHelper::createTables();

            \Piwik\Plugin\Manager::getInstance()->unloadPlugins();
        } catch (Exception $e) {
            static::fail("TEST INITIALIZATION FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }

        include "DataFiles/SearchEngines.php";
        include "DataFiles/Socials.php";
        include "DataFiles/Languages.php";
        include "DataFiles/Countries.php";
        include "DataFiles/Currencies.php";
        include "DataFiles/LanguageToCountry.php";
        include "DataFiles/Providers.php";

        if (!$this->isFixtureSetUp()) {
            DbHelper::truncateAllTables();
        }

        static::createAccessInstance();

        // We need to be SU to create websites for tests
        Piwik::setUserHasSuperUserAccess();

        Cache::deleteTrackerCache();

        static::loadAllPlugins();

        $_GET = $_REQUEST = array();
        $_SERVER['HTTP_REFERER'] = '';

        // Make sure translations are loaded to check messages in English
        if ($this->loadTranslations) {
            Translate::reloadLanguage('en');
            APILanguageManager::getInstance()->setLanguageForUser('superUserLogin', 'en');
        }

        FakeAccess::$superUserLogin = 'superUserLogin';

        \Piwik\SettingsPiwik::$cachedKnownSegmentsToArchive = null;
        \Piwik\CacheFile::$invalidateOpCacheBeforeRead = true;

        if ($this->configureComponents) {
            \Piwik\Plugins\PrivacyManager\IPAnonymizer::deactivate();
            \Piwik\Plugins\PrivacyManager\DoNotTrackHeaderChecker::deactivate();
        }

        if ($this->createSuperUser) {
            self::createSuperUser($this->removeExistingSuperUser);
        }

        if ($setupEnvironmentOnly) {
            return;
        }

        $this->getTestEnvironment()->save();
        $this->getTestEnvironment()->executeSetupTestEnvHook();
        Piwik_TestingEnvironment::addSendMailHook();

        if ($this->overwriteExisting
            || !$this->isFixtureSetUp()
        ) {
            $this->setUp();

            $this->markFixtureSetUp();
            $this->log("Database {$this->dbName} marked as successfully set up.");
        } else {
            $this->log("Using existing database {$this->dbName}.");
        }
    }

    public function getTestEnvironment()
    {
        if ($this->testEnvironment === null) {
            $this->testEnvironment = new Piwik_TestingEnvironment();
            $this->testEnvironment->delete();
        }
        return $this->testEnvironment;
    }

    public function isFixtureSetUp()
    {
        $optionName = get_class($this) . '.setUpFlag';
        return Option::get($optionName) !== false;
    }

    public function markFixtureSetUp()
    {
        $optionName = get_class($this) . '.setUpFlag';
        Option::set($optionName, 1);
    }

    public function performTearDown()
    {
        // Note: avoid run SQL in the *tearDown() metohds because it randomly fails on Travis CI
        // with error Error while sending QUERY packet. PID=XX
        $this->tearDown();

        self::unloadAllPlugins();

        if ($this->dropDatabaseInTearDown) {
            $this->dropDatabase();
        }

        DataTableManager::getInstance()->deleteAll();
        Option::clearCache();
        Site::clearCache();
        Cache::deleteTrackerCache();
        Config::getInstance()->clear();
        ArchiveTableCreator::clear();
        \Piwik\Plugins\ScheduledReports\API::$cache = array();
        \Piwik\Registry::unsetInstance();
        \Piwik\EventDispatcher::getInstance()->clearAllObservers();

        $_GET = $_REQUEST = array();
        Translate::unloadEnglishTranslation();
    }

    public static function loadAllPlugins()
    {
        DbHelper::createTables();
        $pluginsManager = \Piwik\Plugin\Manager::getInstance();
        $plugins = $pluginsManager->getPluginsToLoadDuringTests();
        $pluginsManager->loadPlugins($plugins);

        // Install plugins
        $messages = $pluginsManager->installLoadedPlugins();
        if(!empty($messages)) {
            Log::info("Plugin loading messages: %s", implode(" --- ", $messages));
        }

        // Activate them
        foreach($plugins as $name) {
            if (!$pluginsManager->isPluginActivated($name)) {
                $pluginsManager->activatePlugin($name);
            }
        }
    }

    public static function unloadAllPlugins()
    {
        try {
            $plugins = \Piwik\Plugin\Manager::getInstance()->getLoadedPlugins();
            foreach ($plugins AS $plugin) {
                $plugin->uninstall();
            }
            \Piwik\Plugin\Manager::getInstance()->unloadPlugins();
        } catch (Exception $e) {
        }
    }

    /**
     * Creates a website, then sets its creation date to a day earlier than specified dateTime
     * Useful to create a website now, but force data to be archived back in the past.
     *
     * @param string $dateTime eg '2010-01-01 12:34:56'
     * @param int $ecommerce
     * @param string $siteName
     *
     * @param bool|string $siteUrl
     * @param int $siteSearch
     * @param null|string $searchKeywordParameters
     * @param null|string $searchCategoryParameters
     * @return int    idSite of website created
     */
    public static function createWebsite($dateTime, $ecommerce = 0, $siteName = false, $siteUrl = false,
                                         $siteSearch = 1, $searchKeywordParameters = null,
                                         $searchCategoryParameters = null, $timezone = null)
    {
        if($siteName === false) {
            $siteName = self::DEFAULT_SITE_NAME;
        }
        $idSite = APISitesManager::getInstance()->addSite(
            $siteName,
            $siteUrl === false ? "http://piwik.net/" : $siteUrl,
            $ecommerce,
            $siteSearch, $searchKeywordParameters, $searchCategoryParameters,
            $ips = null,
            $excludedQueryParameters = null,
            $timezone,
            $currency = null
        );

        // Manually set the website creation date to a day earlier than the earliest day we record stats for
        Db::get()->update(Common::prefixTable("site"),
            array('ts_created' => Date::factory($dateTime)->subDay(1)->getDatetime()),
            "idsite = $idSite"
        );

        // Clear the memory Website cache
        Site::clearCache();

        return $idSite;
    }

    /**
     * Returns URL to Piwik root.
     *
     * @return string
     */
    public static function getRootUrl()
    {
        $piwikUrl = Url::getCurrentUrlWithoutFileName();

        $pathBeforeRoot = 'tests';
        // Running from a plugin
        if (strpos($piwikUrl, 'plugins/') !== false) {
            $pathBeforeRoot = 'plugins';
        }

        $testsInPath = strpos($piwikUrl, $pathBeforeRoot . '/');
        if ($testsInPath !== false) {
            $piwikUrl = substr($piwikUrl, 0, $testsInPath);
        }

        // in case force_ssl=1, or assume_secure_protocol=1, is set in tests
        // we don't want to require Travis CI or devs to setup HTTPS on their local machine
        $piwikUrl = str_replace("https://", "http://", $piwikUrl);

        return $piwikUrl;
    }

    /**
     * Returns URL to the proxy script, used to ensure piwik.php
     * uses the test environment, and allows variable overwriting
     *
     * @return string
     */
    public static function getTrackerUrl()
    {
        return self::getRootUrl() . 'tests/PHPUnit/proxy/piwik.php';
    }

    /**
     * Returns a PiwikTracker object that you can then use to track pages or goals.
     *
     * @param int     $idSite
     * @param string  $dateTime
     * @param boolean $defaultInit If set to true, the tracker object will have default IP, user agent, time, resolution, etc.
     * @param bool    $useLocal
     *
     * @return PiwikTracker
     */
    public static function getTracker($idSite, $dateTime, $defaultInit = true, $useLocal = false)
    {
        if ($useLocal) {
            require_once PIWIK_INCLUDE_PATH . '/tests/LocalTracker.php';
            $t = new Piwik_LocalTracker($idSite, self::getTrackerUrl());
        } else {
            $t = new PiwikTracker($idSite, self::getTrackerUrl());
        }
        $t->setForceVisitDateTime($dateTime);

        if ($defaultInit) {
            $t->setTokenAuth(self::getTokenAuth());
            $t->setIp('156.5.3.2');

            // Optional tracking
            $t->setUserAgent("Mozilla/5.0 (Windows; U; Windows NT 5.1; en-GB; rv:1.9.2.6) Gecko/20100625 Firefox/3.6.6 (.NET CLR 3.5.30729)");
            $t->setBrowserLanguage('fr');
            $t->setLocalTime('12:34:06');
            $t->setResolution(1024, 768);
            $t->setBrowserHasCookies(true);
            $t->setPlugins($flash = true, $java = true, $director = false);
        }
        return $t;
    }

    /**
     * Checks that the response is a GIF image as expected.
     * Will fail the test if the response is not the expected GIF
     *
     * @param $response
     */
    public static function checkResponse($response)
    {
        $trans_gif_64 = "R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==";
        $expectedResponse = base64_decode($trans_gif_64);

        $url = "\n =========================== \n URL was: " . PiwikTracker::$DEBUG_LAST_REQUESTED_URL;
        self::assertEquals($expectedResponse, $response, "Expected GIF beacon, got: <br/>\n"
            . var_export($response, true)
            . "\n If you are stuck, you can enable [Tracker] debug=1; in config.ini.php to get more debug info."
            . base64_encode($response)
            . $url
        );
    }

    /**
     * Checks that the response from bulk tracking is a valid JSON
     * string. Will fail the test if JSON status is not success.
     *
     * @param $response
     */
    public static function checkBulkTrackingResponse($response) {
        $data = json_decode($response, true);
        if (!is_array($data) || empty($response)) {
            throw new Exception("Bulk tracking response (".$response.") is not an array: " . var_export($data, true) . "\n");
        }
        if(!isset($data['status'])) {
            throw new Exception("Returned data didn't have a status: " . var_export($data,true));
        }

        self::assertArrayHasKey('status', $data);
        self::assertEquals('success', $data['status'], "expected success, got: " . var_export($data, true));
    }

    public static function makeLocation($city, $region, $country, $lat = null, $long = null, $isp = null)
    {
        return array(LocationProvider::CITY_NAME_KEY    => $city,
                     LocationProvider::REGION_CODE_KEY  => $region,
                     LocationProvider::COUNTRY_CODE_KEY => $country,
                     LocationProvider::LATITUDE_KEY     => $lat,
                     LocationProvider::LONGITUDE_KEY    => $long,
                     LocationProvider::ISP_KEY          => $isp);
    }

    /**
     * Returns the Super User token auth that can be used in tests. Can be used to
     * do bulk tracking.
     *
     * @return string
     */
    public static function getTokenAuth()
    {
        return APIUsersManager::getInstance()->getTokenAuth(
            self::ADMIN_USER_LOGIN,
            UsersManager::getPasswordHash(self::ADMIN_USER_PASSWORD)
        );
    }

    public static function createSuperUser($removeExisting = true)
    {
        $login = self::ADMIN_USER_LOGIN;
        $password = UsersManager::getPasswordHash(self::ADMIN_USER_PASSWORD);
        $token = self::getTokenAuth();

        $model = new \Piwik\Plugins\UsersManager\Model();
        if ($removeExisting) {
            $model->deleteUserOnly($login);
        }

        $user = $model->getUser($login);

        if (empty($user)) {
            $model->addUser($login, $password, 'hello@example.org', $login, $token, Date::now()->getDatetime());
        } else {
            $model->updateUser($login, $password, 'hello@example.org', $login, $token);
        }

        if (empty($user['superuser_access'])) {
            $model->setSuperUserAccess($login, true);
        }

        return $model->getUserByTokenAuth($token);
    }

    /**
     * Create three MAIL and two MOBILE scheduled reports
     *
     * Reports sent by mail can contain PNG graphs when the user specifies it.
     * Depending on the system under test, generated images differ slightly.
     * Because of this discrepancy, PNG graphs are only tested if the system under test
     * has the characteristics described in 'canImagesBeIncludedInScheduledReports'.
     * See tests/README.md for more detail.
     *
     * @see canImagesBeIncludedInScheduledReports
     * @param int $idSite id of website created
     */
    public static function setUpScheduledReports($idSite)
    {
        // fake access is needed so API methods can call Piwik::getCurrentUserLogin(), e.g: 'ScheduledReports.addReport'
        $pseudoMockAccess = new FakeAccess;
        FakeAccess::$superUser = true;
        Access::setSingletonInstance($pseudoMockAccess);

        // retrieve available reports
        $availableReportMetadata = APIScheduledReports::getReportMetadata($idSite, ScheduledReports::EMAIL_TYPE);

        $availableReportIds = array();
        foreach ($availableReportMetadata as $reportMetadata) {
            $availableReportIds[] = $reportMetadata['uniqueId'];
        }

        //@review should we also test evolution graphs?
        // set-up mail report
        APIScheduledReports::getInstance()->addReport(
            $idSite,
            'Mail Test report',
            'day', // overridden in getApiForTestingScheduledReports()
            0,
            ScheduledReports::EMAIL_TYPE,
            ReportRenderer::HTML_FORMAT, // overridden in getApiForTestingScheduledReports()
            $availableReportIds,
            array(ScheduledReports::DISPLAY_FORMAT_PARAMETER => ScheduledReports::DISPLAY_FORMAT_TABLES_ONLY)
        );

        // set-up sms report for one website
        APIScheduledReports::getInstance()->addReport(
            $idSite,
            'SMS Test report, one website',
            'day', // overridden in getApiForTestingScheduledReports()
            0,
            MobileMessaging::MOBILE_TYPE,
            MobileMessaging::SMS_FORMAT,
            array("MultiSites_getOne"),
            array("phoneNumbers" => array())
        );

        // set-up sms report for all websites
        APIScheduledReports::getInstance()->addReport(
            $idSite,
            'SMS Test report, all websites',
            'day', // overridden in getApiForTestingScheduledReports()
            0,
            MobileMessaging::MOBILE_TYPE,
            MobileMessaging::SMS_FORMAT,
            array("MultiSites_getAll"),
            array("phoneNumbers" => array())
        );

        if (self::canImagesBeIncludedInScheduledReports()) {
            // set-up mail report with images
            APIScheduledReports::getInstance()->addReport(
                $idSite,
                'Mail Test report',
                'day', // overridden in getApiForTestingScheduledReports()
                0,
                ScheduledReports::EMAIL_TYPE,
                ReportRenderer::HTML_FORMAT, // overridden in getApiForTestingScheduledReports()
                $availableReportIds,
                array(ScheduledReports::DISPLAY_FORMAT_PARAMETER => ScheduledReports::DISPLAY_FORMAT_TABLES_AND_GRAPHS)
            );

            // set-up mail report with one row evolution based png graph
            APIScheduledReports::getInstance()->addReport(
                $idSite,
                'Mail Test report',
                'day',
                0,
                ScheduledReports::EMAIL_TYPE,
                ReportRenderer::HTML_FORMAT,
                array('Actions_getPageTitles'),
                array(
                     ScheduledReports::DISPLAY_FORMAT_PARAMETER => ScheduledReports::DISPLAY_FORMAT_GRAPHS_ONLY,
                     ScheduledReports::EVOLUTION_GRAPH_PARAMETER => 'true',
                )
            );
        }
    }

    /**
     * Return true if system under test has Piwik core team's most common configuration
     */
    public static function canImagesBeIncludedInScheduledReports()
    {
        $gdInfo = gd_info();
        return
            stristr(php_uname(), self::IMAGES_GENERATED_ONLY_FOR_OS) &&
            strpos( phpversion(), self::IMAGES_GENERATED_FOR_PHP) !== false &&
            strpos( $gdInfo['GD Version'], self::IMAGES_GENERATED_FOR_GD) !== false;
    }

    public static $geoIpDbUrl = 'http://piwik-team.s3.amazonaws.com/GeoIP.dat.gz';
    public static $geoLiteCityDbUrl = 'http://piwik-team.s3.amazonaws.com/GeoLiteCity.dat.gz';

    public static function downloadGeoIpDbs()
    {
        $geoIpOutputDir = PIWIK_INCLUDE_PATH . '/tests/lib/geoip-files';
        self::downloadAndUnzip(self::$geoIpDbUrl, $geoIpOutputDir, 'GeoIP.dat');
        self::downloadAndUnzip(self::$geoLiteCityDbUrl, $geoIpOutputDir, 'GeoIPCity.dat');
    }

    public static function downloadAndUnzip($url, $outputDir, $filename)
    {
        $bufferSize = 1024 * 1024;

        if (!is_dir($outputDir)) {
            mkdir($outputDir);
        }

        $deflatedOut = $outputDir . '/' . $filename;
        $outfileName = $deflatedOut . '.gz';

        if (file_exists($deflatedOut)) {
            return;
        }

        $dump = fopen($url, 'rb');
        $outfile = fopen($outfileName, 'wb');
        $bytesRead = 0;
        while (!feof($dump)) {
            fwrite($outfile, fread($dump, $bufferSize), $bufferSize);
            $bytesRead += $bufferSize;
        }
        fclose($dump);
        fclose($outfile);

        // unzip the dump
        exec("gunzip -c \"" . $outfileName . "\" > \"$deflatedOut\"", $output, $return);
        if ($return !== 0) {
            Log::info("gunzip failed with file that has following contents:");
            Log::info(file_get_contents($outfile));

            throw new Exception("gunzip failed($return): " . implode("\n", $output));
        }
    }

    protected static function executeLogImporter($logFile, $options)
    {
        $python = self::getPythonBinary();

        // create the command
        $cmd = $python
            . ' "' . PIWIK_INCLUDE_PATH . '/misc/log-analytics/import_logs.py" ' # script loc
            . '-ddd ' // debug
            . '--url="' . self::getRootUrl() . 'tests/PHPUnit/proxy/" ' # proxy so that piwik uses test config files
        ;

        foreach ($options as $name => $value) {
            $cmd .= $name;
            if ($value !== false) {
                $cmd .= '="' . $value . '"';
            }
            $cmd .= ' ';
        }

        $cmd .= '"' . $logFile . '" 2>&1';

        // run the command
        exec($cmd, $output, $result);
        if ($result !== 0) {
            throw new Exception("log importer failed: " . implode("\n", $output) . "\n\ncommand used: $cmd");
        }

        return $output;
    }

    public static function siteCreated($idSite)
    {
        return Db::fetchOne("SELECT COUNT(*) FROM " . Common::prefixTable('site') . " WHERE idsite = ?", array($idSite)) != 0;
    }

    public static function goalExists($idSite, $idGoal)
    {
        return Db::fetchOne("SELECT COUNT(*) FROM " . Common::prefixTable('goal') . " WHERE idgoal = ? AND idsite = ?", array($idGoal, $idSite)) != 0;
    }


    /**
     * Connects to MySQL w/o specifying a database.
     */
    public static function connectWithoutDatabase()
    {
        $dbConfig = Config::getInstance()->database;
        $oldDbName = $dbConfig['dbname'];
        $dbConfig['dbname'] = null;

        Db::createDatabaseObject($dbConfig);

        $dbConfig['dbname'] = $oldDbName;
    }

    /**
     * Sets up access instance.
     */
    public static function createAccessInstance()
    {
        Access::setSingletonInstance(null);
        Access::getInstance();
        Piwik::postEvent('Request.initAuthenticationObject');
    }

    public function dropDatabase($dbName = null)
    {
        $dbName = $dbName ?: $this->dbName;

        $this->log("Dropping database '$dbName'...");

        $config = _parse_ini_file(PIWIK_INCLUDE_PATH . '/config/config.ini.php', true);
        $originalDbName = $config['database']['dbname'];
        if ($dbName == $originalDbName
            && $dbName != 'piwik_tests'
        ) { // santity check
            throw new \Exception("Trying to drop original database '$originalDbName'. Something's wrong w/ the tests.");
        }

        DbHelper::dropDatabase($dbName);
    }

    public function log($message)
    {
        if ($this->printToScreen) {
            echo $message . "\n";
        }
    }

    // NOTE: since API_Request does sanitization, API methods do not. when calling them, we must
    // sometimes do sanitization ourselves.
    public static function makeXssContent($type, $sanitize = false)
    {
        $result = "<script>$('body').html('$type XSS!');</script>";
        if ($sanitize) {
            $result = Common::sanitizeInputValue($result);
        }
        return $result;
    }
}

// TODO: remove when other plugins don't use BaseFixture
class Test_Piwik_BaseFixture extends Fixture
{
}

// needed by tests that use stored segments w/ the proxy index.php
class Test_Access_OverrideLogin extends Access
{
    public function getLogin()
    {
        return 'superUserLogin';
    }
}