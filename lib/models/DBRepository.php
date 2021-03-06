<?php

use Gitonomy\Git\Repository;
use Gitonomy\Git\Reference\Branch;
use LibPostgres\LibPostgresDriver;

// for external `diff`
use Symfony\Component\Process\ProcessBuilder;

class DBRepository
{
    private static $oGit = null;
    private static $aDBCredentials = null;
    private static $oDB = null;
    private static $sDirectory = null;
    private static $sDatabase = null;
    private static $sSchemasPath = 'schemas/';
    private static $sEnv = 'development';

    private static $aForwardableObjectsIndexes = null;
    private static $aObjectsIndexes = null;

    private static $oLastAppliedObject = null;

    // settings of features
    private static $aSettings = array();

    // databases user can access and work with
    public static $aDatabases = array();

    // branches of chosen repository
    public static $aBranches = array();

    /**
     * Reads settings from JSON configuration file.
     *
     * @param none
     *
     * @return none
     */

    public static function readGlobalSettings()
    {
        $sFileName = './../lib/config/settings.json';

        if (! file_exists($sFileName) or ! is_readable($sFileName)) {
            self::$aSettings = array();
            return;
        }

        $aSettings = json_decode(file_get_contents($sFileName), 'associative');

        // settings
        self::$aSettings = isset($aSettings['settings']) ? $aSettings['settings'] : array();
    }

    /**
     * Gets setting value
     *
     * @param string $sIndex composite, dot-imploded array index, e.g. 'not_in_git.active'
     * @param mixed $mDefaultValue default value for case $sIndex is absent in settings array
     *
     * @return mixed setting value
     */

    public static function getSettingValue($sIndex, $mDefaultValue = false)
    {
        $aSegment = self::$aSettings; //don't worry about performance, this use copy-on-write
        foreach (explode(".", $sIndex) as $sKey) {
            if (! is_array($aSegment) or ! array_key_exists($sKey, $aSegment)) {
                return $mDefaultValue;
            }
            $aSegment = $aSegment[$sKey];
        }

        return $aSegment;
    }

    /**
     * Reads databases from JSON configuration file.
     *
     * @param none
     *
     * @return none
     */

    public static function readDatabases()
    {
        $sFileName = './../lib/config/databases.json';

        if (! file_exists($sFileName) or ! is_readable($sFileName)) {
            self::$aDatabases = array();
            return;
        }

        $aDatabases = json_decode(file_get_contents($sFileName), 'associative');

        // allowed databases
        self::$aDatabases = isset($aDatabases['databases']) ? $aDatabases['databases'] : array();

        // save indexes
        foreach (self::$aDatabases as $sIndex => $aDatabase) {
            self::$aDatabases[$sIndex]['index'] = $sIndex;
        }
    }

    public static function sameDatabasesExist()
    {
        $aDatabasesNames = array();
        foreach (self::getDatabases() as $aDatabase) {
            if (isset($aDatabase['credentials']['db_name'])) {
                $aDatabasesNames []= $aDatabase['credentials']['db_name'];
            }
        }
        return sizeof(array_unique($aDatabasesNames)) != sizeof($aDatabasesNames);
    }

    /**
     * Returns allowed databases.
     *
     * @param none
     *
     * @return array databases
     */

    public static function getDatabases()
    {
        return self::$aDatabases;
    }

    /**
     * Returns current database.
     *
     * @param none
     *
     * @return string database
     */

    public static function getCurrentDatabase()
    {
        return self::$sDatabase;
    }

    /**
     * Returns DB credentials.
     *
     * @param none
     *
     * @return array credentials
     */

    public static function getDBCredentials()
    {
        return self::$aDBCredentials;
    }

    /**
     * Returns last applied object.
     *
     * @param none
     *
     * @return object
     */

    public static function getLastAppliedObject()
    {
        return self::$oLastAppliedObject;
    }

    /**
     * Sets last applied object.
     *
     * @param object last applied object
     *
     * @return none
     */

    public static function setLastAppliedObject($oLastAppliedObject)
    {
        self::$oLastAppliedObject = $oLastAppliedObject;
    }

    /**
     * Returns existing branches.
     *
     * @param none
     *
     * @return array branches
     */

    public static function getBranches()
    {
        return self::$aBranches;
    }

    /**
     * Returns current branch.
     *
     * @param none
     *
     * @return string current branch
     */

    public static function getCurrentBranch()
    {
        $aBranches = trim(self::$oGit->run('branch'));
        $aBranches = explode("\n", $aBranches);

        $sCurrentBranch = 'master';

        foreach ($aBranches as $sBranch) {
            $sBranch = trim($sBranch);
            if ($sBranch and $sBranch[0] == '*') {
                $sBranch = str_replace("* ", "", $sBranch);
                $sBranch = str_replace("(no branch)", "", $sBranch); // git 1.7
                $sBranch = str_replace("(detached from ", "", $sBranch);
                $sBranch = str_replace("(HEAD detached at ", "", $sBranch); // git 2.0+
                $sBranch = str_replace(")", "", $sBranch);
                $sBranch = trim($sBranch);
                $sCurrentBranch = $sBranch;
            }
        }

        if (! $sCurrentBranch) {
            // get hash
            $sCurrentBranch = trim(self::$oGit->run('rev-parse', array('HEAD')));
        }

        return $sCurrentBranch;
    }

    /**
     * Returns current environment (read from settings).
     *
     * @param none
     *
     * @return string current env
     */

    public static function getEnv()
    {
        return self::$sEnv;
    }

    /**
     * Takes index of single database. Returns allowed databases.
     *
     * @param string database index
     *
     * @return array|false one database
     */

    public static function useDatabase($sDatabaseIndex)
    {
        // all databases from config
        $aDatabases = self::getDatabases();

        // we do not know it
        if (! isset($aDatabases[$sDatabaseIndex])) {
            throw new Exception("There is no database '$sDatabaseIndex'.");
        }

        $aDatabase = $aDatabases[$sDatabaseIndex];

        // no git root
        if (! isset($aDatabase['git_root'])) {
            throw new Exception("There is no git_root '$sDatabaseIndex'.");
        }

        // no access credentials
        if (! isset($aDatabase['credentials'])) {
            throw new Exception("There is no credentials for '$sDatabaseIndex'.");
        }

        // schemas_path can be overriden
        if (isset($aDatabase['schemas_path'])) {
            if (! preg_match("~/$~uixs", $aDatabase['schemas_path'])) {
                // add slash to the end
                $aDatabase['schemas_path'] .= '/';
            }
            self::$sSchemasPath = $aDatabase['schemas_path'];
        }

        // make connection
        self::$aDBCredentials = $aDatabase['credentials'];
        self::$oDB = new LibPostgresDriver(self::$aDBCredentials);
        // check connection
        $sVersion = self::$oDB->selectField("SHOW server_version_num;");

        // build version
        self::$aDBCredentials['version'] = floor($sVersion /  10000) . "." . floor($sVersion / 100) % 10;
        // to show in header
        $aDatabase['version'] = self::$aDBCredentials['version'];

        // share connections
        User::$oDB = self::$oDB;
        Database::$oDB = self::$oDB;
        DatabaseObject::$oDB = self::$oDB;

        // make git
        if (! preg_match("~/$~uixs", $aDatabase['git_root'])) {
            // add slash to the end
            $aDatabase['git_root'] .= '/';
        }
        self::$oGit = new Repository($aDatabase['git_root']);

        // branches
        self::$aBranches = array();
        foreach (self::$oGit->getReferences()->getLocalBranches() as $oBranch) {
            self::$aBranches []= array(
                'name' => trim($oBranch->getName()),
                'hash' => $oBranch->getCommitHash(),
            );
        }

        // save params
        self::$sDatabase = $sDatabaseIndex;
        self::$sDirectory = $aDatabase['git_root'];

        // check if directory exists
        if (! file_exists($sWorkingDir = self::$sDirectory . self::$sSchemasPath)) {
            throw new Exception("There is no directory '$sWorkingDir'.");
        }

        // merge local settings with global settings
        if (isset($aDatabase['settings'])) {
            $aSettings = $aDatabase['settings'];
            if (is_array($aSettings)) {
                self::$aSettings = array_replace_recursive(self::$aSettings, $aSettings);
            }
        }

        // environment, see getFileContent and processContentBasingOnEnvironment
        self::$sEnv = self::getSettingValue('env', self::$sEnv);

        // return
        return $aDatabase;
    }

    /**
     * Gets commits of git branch
     *
     * @param none
     *
     * @return array Commits
     */

    public static function getCommits()
    {
        $iCommitsLimit = self::getSettingValue('commits_list.limit');
        if (! $iCommitsLimit) {
            $iCommitsLimit = null;
        }

        $aCommitsRaw = self::$oGit->getLog(null, null, 0, $iCommitsLimit);
        $aCommits = array(
            'commits' => array(),
            'current_commit_hash' => '',
        );

        $bIsHeadDetached = self::$oGit->isHeadDetached();

        foreach ($aCommitsRaw as $aCommit) {
            if ($bIsHeadDetached) {
                $bActive = $aCommit->getHash() == self::$oGit->getHead()->getHash();
            } else {
                $bActive = $aCommit->getHash() == self::$oGit->getHeadCommit()->getHash();
            }

            if ($bActive) {
                $aCommits['current_commit_hash'] = $aCommit->getHash();
            }

            $aBranchesRaw = $aCommit->resolveReferences();

            $aBranches = array();
            foreach ($aBranchesRaw as $aBranch) {
                if ($aBranch instanceof Branch) {
                    if ($aBranch->isLocal()) {
                        $aBranches [] = array(
                            'branch_name' => $aBranch->getName(),
                        );
                    }
                }
            }

            $aCommits['commits'] []= array(
                'commit_hash' => $aCommit->getHash(),
                'commit_message' => $aCommit->getMessage(),
                'commit_active' => $bActive ? "active" : "passive",
                'commit_author' => $aCommit->getAuthorName(),
                'resolved_branches' => $aBranches,
            );
        }
        return $aCommits;
    }

    /**
     * Gets allowed database object types
     *
     * @param none
     *
     * @return array types
     */

    private static function getObjectsIndexes()
    {
        if (self::$aObjectsIndexes !== null) {
            return self::$aObjectsIndexes;
        }

        return self::$aObjectsIndexes = self::$oDB->selectColumn("
            SELECT index
                FROM postgresql_deployer.migrations_objects
                ORDER BY rank ASC;
        ");
    }

    /**
     * Gets allowed forwardable database object types
     *
     * @param none
     *
     * @return array types
     */

    private static function getForwardableObjectsIndexes()
    {
        if (self::$aForwardableObjectsIndexes !== null) {
            return self::$aForwardableObjectsIndexes;
        }

        return self::$aForwardableObjectsIndexes = self::$oDB->selectColumn("
            SELECT index
                FROM postgresql_deployer.migrations_objects
                WHERE (params->>'is_forwardable')::boolean
                ORDER BY rank ASC;
        ");
    }

    /**
     * Checkouts to specific commit by its hash
     *
     * @param string commit hash
     *
     * @return array types
     */

    public static function checkout($sHash)
    {
        self::$oGit->run("checkout", array($sHash));
        return self::reload();
    }

    /**
     * Get schemas in git repository (schemas are subdirectories in database directory) + database schemas
     *
     * @param none
     *
     * @return array schemas
     */

    private static function getSchemas()
    {
        // schemas from git
        $aSchemasRaw = self::getListOfFiles(self::$sDirectory . self::$sSchemasPath);
        $aSchemas = array();

        foreach ($aSchemasRaw as $sFile) {
            $sSchemaName = self::getBaseName($sFile['file']);
            $aSchemas[$sSchemaName] = $sSchemaName;
        }

        // merge with schemas from database
        $aSchemas = array_merge($aSchemas, Database::getSchemas());

        // some order
        sort($aSchemas);

        return $aSchemas;
    }

    /**
     * Reloads current state of git in compare with current database state
     *
     * @param none
     *
     * @return array schemas
     */

    private static function reload()
    {
        //
        $aSchemas = self::getSchemas();

        // current state of migration table
        DatabaseObject::readMigrations();

        $aResult = array(
            'schemas' => array(),
        );

        //
        $bShowObjectsNotInGit = self::getSettingValue('not_in_git.active');
        $sExcludeRegexpShowObjectsNotInGit = '';
        if ($bShowObjectsNotInGit) {
            $sExcludeRegexpShowObjectsNotInGit = self::getSettingValue('not_in_git.exclude_regexp', '');
        }

        // for each schema
        foreach ($aSchemas as $sSchema) {

            // for each object type - index
            foreach (self::getObjectsIndexes() as $sObjectIndex) {

                // container for objects in schema
                $aSchema = array();

                $aSchema['database_name'] = self::$sDatabase;
                $aSchema['schema_name'] = $sSchema;
                $aSchema['object_index'] = $sObjectIndex;

                // all objects of given type in given schema
                $aFiles = self::getListOfFiles(self::$sDirectory . self::$sSchemasPath . $sSchema . "/" . $sObjectIndex);
                // files have hash != ''

                if ($bShowObjectsNotInGit) {
                    // ALL objects in database
                    $aObjects = Database::getObjectsAsVirtualFiles($sSchema, $sObjectIndex);
                    // hash == ''

                    if ($sExcludeRegexpShowObjectsNotInGit) {
                        // filter objects using not_in_git.exclude_regexp
                        foreach ($aObjects as $sKey => $aObjectData) {
                            $sObjectNameWithSchema = $sSchema . '.' . $sKey;
                            if (preg_match('~' . $sExcludeRegexpShowObjectsNotInGit . '~uixs', $sObjectNameWithSchema)) {
                                // do not show as NOT IN GIT
                                unset($aObjects[$sKey]);
                            }
                        }
                    }

                    // objects not under git will still have hash = ''
                    $aFiles = array_merge($aObjects, $aFiles);
                }

                //
                sort($aFiles);

                // let's walk through files
                foreach ($aFiles as $aFile) {

                    $sObjectNameName = self::getBaseNameWithoutExtension($aFile['file']);
                    $aDependencies = null;

                    // is object under git?
                    $bInGit = $aFile['hash'] != '';
                    $bNotInGit = ! $bInGit;

                    // make object
                    $oDatabaseObject = DatabaseObject::make(
                        self::$sDatabase,
                        $sSchema,
                        $sObjectIndex,
                        $sObjectNameName,
                        $bInGit ? self::getFileContent($aFile['file']) : ''
                    );

                    // has object been changed (git contains one version, but db contains another)
                    if ($oDatabaseObject->hasChanged($aFile['hash'])) {

                        if ($bInGit) {
                            $oDiff = $oDatabaseObject->createDiff();
                            $iInsertions = $oDiff->getInsertionsCount();
                            $iDeletions = $oDiff->getDeletionsCount();
                        } else {
                            $oDiff = null;
                            $iInsertions = $iDeletions = null;
                        }

                        // get diff (insertions and deletions)
                        $bCanBeForwarded = null;
                        if ($bInGit and $oDatabaseObject instanceof IForwardable) {
                            $bCanBeForwarded = $oDatabaseObject->canBeForwarded();
                        }

                        // we should show dependencies
                        $aDependencies = $oDatabaseObject->getObjectDependencies();

                        // is object new? (in git and not in database)
                        $bIsNew = ! $oDatabaseObject->objectExists();

                        //
                        if (! $bIsNew and $bInGit) {
                            $bSignatureChanged = $oDatabaseObject->signatureChanged();
                            $bReturnTypeChanged = ($oDatabaseObject instanceof StoredFunction) && $oDatabaseObject->returnTypeChanged();
                            $bGrantsChanged = ($oDatabaseObject instanceof StoredFunction) && $oDatabaseObject->grantsChanged();
                        } else {
                            // it has no sense showing it
                            $bSignatureChanged = false;
                            $bReturnTypeChanged = false;
                            $bGrantsChanged = false;
                        }

                        // work with references list to show it in panel
                        $aReferences = array();

                        if ($oDiff) { // only if $oDiff exists
                            $ReferencesRaw = $oDiff->getReferences();

                            foreach ($ReferencesRaw as $oReference) {
                                $aReferences []= array(
                                    'reference_name' => (string)$oReference,
                                );
                            }
                        }

                        // save data to be shown at diff panel
                        $aSchema['objects'] []= array(
                            'object_name' => $sObjectNameName,
                            'object_index' => $sObjectIndex,

                            // dependencies
                            'dependencies' => $aDependencies,
                            'dependencies_exist' => $aDependencies ? true : null,
                            'dependencies_will_be_applied_automatically' => $oDatabaseObject instanceof Type,
                            'dependencies_will_be_applied_automatically_only_by_forwarding' => ($oDatabaseObject instanceof Table) and $bCanBeForwarded,
                            'dependencies_require_manual_deployment' => ($oDatabaseObject instanceof Table) and ! $bCanBeForwarded,
                            'references' => $aReferences,
                            'references_exist' => $aReferences ? true : null,

                            // signatures
                            'signature_changed' => $bSignatureChanged,
                            'return_type_changed' => $bReturnTypeChanged,
                            'grants_changed' => $bGrantsChanged,

                            // git info
                            'manual_deployment_required' => (($oDatabaseObject instanceof IForwardable) and $bInGit) ? true : null,
                            'can_be_forwarded' => $bCanBeForwarded,
                            'insertions' => $iInsertions,
                            'deletions' => $iDeletions,
                            'new_object' => $bIsNew,
                            'not_in_git' => $bNotInGit,

                            // available operations:
                            // definition (CREATE SMTH) is useful only for non-git objects
                            'define' => $bNotInGit and $oDatabaseObject->isDefinable(),
                            // drop too
                            'drop' => $bNotInGit and $oDatabaseObject->isDroppable(),
                            // description (\dt, describe type, \sf) is useful for all existing objects
                            'describe' => ! $bIsNew and $oDatabaseObject->isDescribable(),

                            // save object to work with collection of them to find references
                            'database_object' => $oDatabaseObject,
                        );
                    }

                }

                if (! empty($aSchema['objects'])) {
                    $aSchema['objects_count'] = count($aSchema['objects']);
                    $aResult['schemas'] []= $aSchema;
                }

            }

        }

        // some useful information about forwarding
        $aResult['stat'] = array();

        foreach (self::getForwardableObjectsIndexes() as $sObjectIndex) {
            $aResult['stat']['can_be_forwarded'][$sObjectIndex] = array();
            $aResult['stat']['cannot_be_forwarded'][$sObjectIndex] = array();

            // collect objects known to be potentially forwarded
            $aObjectsToBeForwarded[$sObjectIndex] = array();
        }

        //
        foreach ($aResult['schemas'] as $aSchema) {
            foreach ($aSchema['objects'] as $aObject) {
                if ($aObject['can_be_forwarded']) {
                    $aObjectsToBeForwarded[$aObject['object_index']] []= $aObject['database_object'];
                }
            }
        }

        // topological sort of references graph
        $aTablesOrder = self::orderByReferences($aObjectsToBeForwarded['tables']);
        // it will returns ordered list of object that can be ordered :)

        // remove objects from response
        foreach ($aResult['schemas'] as $sSchemaKey => $aSchema) {
            foreach ($aSchema['objects'] as $sObjectKey => & $aObject) {
                // there is no need to put this object in response
                unset($aResult['schemas'][$sSchemaKey]['objects'][$sObjectKey]['database_object']);

                // qualified object name (e.g. schema_name.tables.table_name)
                $sQualifiedObjectName = (string)$aObject['database_object'];

                // alias for object in response
                $aObject = & $aResult['schemas'][$sSchemaKey]['objects'][$sObjectKey];
                $sObjectIndex = $aSchema['object_index'];

                if (! in_array($sObjectIndex, self::getForwardableObjectsIndexes())) {
                    // skip seeds, functions, types, triggers
                    continue;
                }

                if ($aObject['not_in_git']) {
                    // skip objects not in git
                    continue;
                }

                if (! $aObject['can_be_forwarded']) {
                    // for messages in panel
                    $aResult['stat']['cannot_be_forwarded'][$sObjectIndex] []= $sQualifiedObjectName;
                    // skip object which cannot be forwarded
                    continue;
                }

                // now let's figure out if we can forward table?

                // did we get order after topological sort
                $bIsOrderSet = $sObjectIndex == 'tables'
                                ? isset($aTablesOrder[$sQualifiedObjectName]) // only for tables
                                : true; // order for sequences/queries will be set to 0, they are ordered by name (see apply())

                $iOrder = ($bIsOrderSet and $sObjectIndex == 'tables')
                                ? $aTablesOrder[$sQualifiedObjectName]
                                : 0;

                $sArrayIndex = ''; // can_be_forwarded or cannot_be_forwarded

                // can be forwarded with defined order?
                if ($bIsOrderSet) {
                    // yep
                    $aObject['forward_order'] = $iOrder;
                    $aObject['manual_deployment_required'] = null;
                    $sArrayIndex = 'can_be_forwarded';
                } else {
                    // nope
                    $aObject['can_be_forwarded'] = null;
                    $aResult['stat']['cannot_be_forwarded']['tables'] []= $sQualifiedObjectName;
                    $sArrayIndex = 'cannot_be_forwarded';
                }

                // for messages in panel (list of forwarding objects)
                if ($sObjectIndex == 'tables') {
                    $aResult['stat'][$sArrayIndex][$sObjectIndex][$iOrder]= $sQualifiedObjectName;
                } else {
                    // the difference is in indexation
                    $aResult['stat'][$sArrayIndex][$sObjectIndex] []= $sQualifiedObjectName;
                }
            }
        }

        // sorting by order to show it in information panel
        ksort($aResult['stat']['can_be_forwarded']['tables']);
        sort($aResult['stat']['can_be_forwarded']['sequences']);
        natsort($aResult['stat']['can_be_forwarded']['queries_before']);
        natsort($aResult['stat']['can_be_forwarded']['queries_after']);
        // remove keys
        $aResult['stat']['can_be_forwarded']['tables'] = array_values($aResult['stat']['can_be_forwarded']['tables']);

        //
        return $aResult;
    }

    /**
     * Returns ordered list of tables to forward, starting from tables without references at all
     *
     * @param array tables to be forwarded
     *
     * @return array ordered list
     */

    private static function orderByReferences($aTablesToBeForwarded) {
        // result
        $aTablesOrder = array();

        // forward order
        $iOrder = 0;

        do {
            // count of references removed
            $iCount = 0;

            // foreach table
            for($i = 0; $i < sizeof($aTablesToBeForwarded); $i ++) {
                $oTable = $aTablesToBeForwarded[$i];

                // does table have references?
                if (! $oTable->getDiff()->getReferences()) {
                    // remember it
                    $sTable = (string)$oTable;
                    if (! isset($aTablesOrder[$sTable])) {
                        $aTablesOrder[$sTable] = ++ $iOrder;
                    }
                    // remove all references on this table
                    for($j = 0; $j < sizeof($aTablesToBeForwarded); $j ++) {
                        $oTableWithReference = $aTablesToBeForwarded[$j];
                        $iCountRemoved = $oTableWithReference->getDiff()->removeReference($oTable);
                        $iCount = max($iCount, $iCountRemoved);
                    }
                }
            }
        } while ($iCount); // while there is reference removed

        return $aTablesOrder;
    }

    /**
     * Returns list of files in directory
     *
     * @param string directory
     *
     * @return array files
     */

    private static function getListOfFiles($sDirectory)
    {
        if (substr($sDirectory, -1) != "/") {
            $sDirectory .= "/";
        }

        if (! is_dir($sDirectory) or ! file_exists($sDirectory) or ! is_readable($sDirectory)) {
            return array();
        }

        $aResult = array();
        $rHandle = opendir($sDirectory);

        if (! $rHandle) {
            return array();
        }

        while (false !== ($sFile = readdir($rHandle))) {
            if ($sFile and $sFile[0] != '.') {
                $sFile = $sDirectory . $sFile;

                if(! is_dir($sFile)){
                    $aResult [self::getBaseNameWithoutExtension($sFile)]= array(
                        'file' => $sFile,
                        'hash' => self::getFileHash($sFile),
                    );
                } else {
                    $aResult []= array(
                        'file' => $sFile,
                        'hash' => '',
                    );
                }
            }
        }

        closedir($rHandle);
        return $aResult;
    }

    /**
     * Makes temporary file with given content
     *
     * @param string content
     *
     * @return string filename
     */

    public static function makeTemporaryFile($sContent)
    {
        $sFileName = tempnam(sys_get_temp_dir(), 'pgdeployer');
        file_put_contents($sFileName, $sContent);
        return $sFileName;
    }

    /**
     * Returns diff between object in git and object saved in database
     *
     * @param string schema name
     * @param string object index
     * @param string object name
     * @param integer context
     *
     * @return string diff as HTML
     */

    public static function getDiffAsHTML($sSchemaName, $sObjectIndex, $sObjectName, $iContext = 5)
    {
        // read file from git
        $sInRepository = self::getFileContent(
            self::getAbsoluteFileName(self::makeRelativeFileName($sSchemaName, $sObjectIndex, $sObjectName))
        );

        // make object via git
        $oObject = DatabaseObject::make(
            self::$sDatabase,
            $sSchemaName,
            $sObjectIndex,
            $sObjectName,
            '', ''
        );

        // read from database
        $sInDatabase = $oObject->getObjectContentInDatabase();

        // create 2 temporary files
        $sFileInRepository = self::makeTemporaryFile($sInRepository);
        $sFileInDatabase = self::makeTemporaryFile($sInDatabase);

        // make diff process
        $oBuilder = new ProcessBuilder(array(
            'diff',
            $sFileInDatabase,
            $sFileInRepository,
            '-U ' . $iContext
        ));
        $oDiff = $oBuilder->getProcess();
        $oDiff->run();

        $sOutput = $oDiff->getOutput();

        // process_output
        $sOutput = preg_replace("~^---.*$~uixm", "", $sOutput);
        $sOutput = preg_replace("~^\+\+\+.*$~uixm", "", $sOutput);

        $sOutput = preg_replace("~^-(.*)$~uixm", "<del>$1</del>", $sOutput);
        $sOutput = preg_replace("~^\+(.*)$~uixm", "<ins>$1</ins>", $sOutput);
        $sOutput = preg_replace("~^(@@.+@@)$~uixm", "<tt>$1</tt>", $sOutput);
        $sOutput = preg_replace("~^\s~uixm", "", $sOutput);
        $sOutput = trim($sOutput);

        unlink($sFileInRepository);
        unlink($sFileInDatabase);

        return array(
            'in_database' => $sOutput,
        );
    }

    /**
     * Returns hash of file
     *
     * @param string filename
     *
     * @return string hash
     */

    private static function getFileHash($sFilename)
    {
        return DatabaseObject::getHash(self::getFileContent($sFilename));
    }

    /**
     * Strips lines of files based on current environment
     *
     * @param string all environment query
     *
     * @return string current environment query
     */

    protected static function processContentBasingOnEnvironment($sQuery)
    {
        /*
            -- @test,production
            SELECT 1;
            -- @test,production
        */
        $sQuery = preg_replace_callback('~\s*--\s*@([^\s]+)*(.+?)--\s*@([^\s]+)~uixs', function($aMatches) {
            $bEnvMatch = ($aMatches[1] == $aMatches[3]) &&
                         in_array(self::$sEnv, explode(',', $aMatches[1]));
            return $bEnvMatch ? $aMatches[2] : '';
        }, $sQuery);

        return $sQuery;
    }

    /**
     * Returns hash of file
     *
     * @param string absolute filename
     *
     * @return string hash
     */

    public static function getFileContent($sFilename)
    {
        if (! file_exists($sFilename)) {
            return '';
        }
        $sContent = file_get_contents($sFilename);
        $sContent = self::processContentBasingOnEnvironment($sContent);
        return $sContent;
    }



    /**
     * Returns basename of file
     *
     * @param string absolute or relative filename
     *
     * @return string basenae
     */

    private static function getBaseName($sFilename)
    {
        $aPathInfo = pathinfo($sFilename);
        return $aPathInfo['basename'];
    }

    /**
     * Returns basename of file without extension
     *
     * @param string absolute or relative filename
     *
     * @return string basename
     */

    private static function getBaseNameWithoutExtension($sFilename)
    {
        $aPathInfo = pathinfo($sFilename);
        return $aPathInfo['filename'];
    }

    /**
     * Makes absolute filename based on relative filename inside git repository and database
     *
     * @param string relative filename
     *
     * @return string absolute filename
     */

    public static function getAbsoluteFileName($sRelativeFileName)
    {
        return self::$sDirectory . self::$sSchemasPath . $sRelativeFileName;
    }

    /**
     * Makes relative filename based on object data
     *
     * @param string schema name
     * @param string object index
     * @param string object name
     *
     * @return string absolute filename
     */

    public static function makeRelativeFileName($sSchemaName, $sObjectIndex, $sObjectName) {
        return $sSchemaName . "/" . $sObjectIndex . "/" . $sObjectName . ".sql";
    }

    /**
     * Deploys (applies) given objects to database
     *
     * @param array objects
     * @param boolean imitate? - fill migration table without performing deployment
     *
     * @return void
     */

    public static function apply($aObjects, $bImitate)
    {
        if (! $aObjects) {
            return;
        }

        // to fill column in migration_log
        DatabaseObject::$sCommitHash = self::$oGit->getHeadCommit()->getHash();

        // to remember we need imitation
        DatabaseObject::$bImitate = $bImitate;

        // packets for objects
        $aForwarded = array();
        $aNonForwarded = array();
        $aTypes = array();
        $aFunctions = array();
        $aSeeds = array();
        $aTriggers = array();

        //
        foreach (self::getForwardableObjectsIndexes() as $sObjectIndex) {
            $aForwarded[$sObjectIndex] = array();
            $aNonForwarded[$sObjectIndex] = array();
        }

        // for each object
        foreach ($aObjects as $aObjectInfo) {
            //
            $sRelativeFileName = $aObjectInfo['object_name'];
            $bForwarded = $aObjectInfo['forwarded'];
            $iForwardOrder = $aObjectInfo['forward_order'];
            if (! $iForwardOrder) {
                $iForwardOrder = 0;
            }

            // exploding by / to get object data
            $aParts = explode("/", $sRelativeFileName);

            $sSchemaName = $aParts[0];
            $sObjectIndex = $aParts[1];
            $sBaseName = $aParts[2];

            $sRelativeFileName = self::makeRelativeFileName($sSchemaName, $sObjectIndex, $sBaseName);

            // make object
            $oObject = DatabaseObject::make(
                self::$sDatabase,
                $sSchemaName,
                $sObjectIndex,
                $sBaseName,
                self::getFileContent(self::getAbsoluteFileName($sRelativeFileName))
            );

            // sorting into 4 types
            if ($oObject instanceof IForwardable) {
                if ($bForwarded) {
                    // creating diff for forwarding
                    $oObject->createDiff();
                    //
                    if ($iForwardOrder) {
                        $aForwarded[$sObjectIndex][$iForwardOrder] = $oObject;
                    } else {
                        $aForwarded[$sObjectIndex][(string)$oObject]= $oObject;
                    }
                } else {
                    //
                    $aNonForwarded[$sObjectIndex][(string)$oObject] = $oObject;
                }
            } else if ($sObjectIndex == 'functions') {
                $aFunctions[(string)$oObject] = $oObject;
            } else if ($sObjectIndex == 'types') {
                $aTypes []= $oObject;
            } else if ($sObjectIndex == 'seeds') {
                $aSeeds []= $oObject;
            } else if ($sObjectIndex == 'triggers') {
                $aTriggers []= $oObject;
            }
        }

        // sort by names in key, order is 0
        ksort($aForwarded['queries_before']);
        ksort($aForwarded['queries_after']);
        ksort($aForwarded['sequences']);

        // sort by topological order in key
        ksort($aForwarded['tables']);

        // symbolic names of functions (schema/functions/name)
        // to compare automatically dropped functions set and
        // functions chosen for deploying
        $aFunctionsKeys = array_keys($aFunctions);

        // the heart of deployer - single transaction
        try {
            self::$oDB->startTransaction();

            // changes in tables and types may cause some function to be droppped
            $aDroppedFunctions = array();

            foreach (array('queries_before', 'sequences', 'tables') as $sForwardableIndex) {

                // forwarding queries/sequences/tables - deploying diff
                foreach ($aForwarded[$sForwardableIndex] as $iForwardOrder => $oForwardableObject) {
                    $aForwardResult = $oForwardableObject->forward();
                    if ($sForwardableIndex == 'tables') {
                        $aDroppedFunctions = array_merge($aDroppedFunctions, $aForwardResult);
                    }
                    $oForwardableObject->upsertMigration();
                }

                // deploying queries/sequences/tables (imitation)
                // only upsert migration, user deploys table by himself
                foreach ($aNonForwarded[$sForwardableIndex] as $oNonForwardableObject) {
                    $oNonForwardableObject->applyObject();
                    $oNonForwardableObject->upsertMigration();
                }

            }

            // deploying seeds
            foreach ($aSeeds as $aSeed) {
                $aSeed->applyObject();
                $aSeed->upsertMigration();
            }

            // continue with types
            foreach ($aTypes as $aType) {
                $aDroppedFunctions = array_merge($aDroppedFunctions, $aType->applyObject());
                $aType->upsertMigration();
            }

            // applying types and forwarding tables may cause some functions to be dropped
            // we have to create them once again
            foreach ($aDroppedFunctions as $sDroppedFunctionKey => $aDroppedFunction) {

                // is dropped function already to be deployed?
                $bAlreadyInListForDeploy = in_array($sDroppedFunctionKey, $aFunctionsKeys);

                if (! $bAlreadyInListForDeploy) {
                    // no, we have to add it
                    $sDroppedFileName = self::makeRelativeFileName(
                                            $aDroppedFunction->sSchemaName,
                                            $aDroppedFunction->sObjectIndex,
                                            $aDroppedFunction->sObjectName);
                    //
                    $aFunctions[$sDroppedFunctionKey] = DatabaseObject::make(
                        self::$sDatabase,
                        $aDroppedFunction->sSchemaName,
                        $aDroppedFunction->sObjectIndex,
                        $aDroppedFunction->sObjectName,
                        self::getFileContent(self::getAbsoluteFileName($sDroppedFileName))
                    );
                }
            }

            // deploying functions
            foreach ($aFunctions as $aFunction) {
                $aFunction->applyObject();
                $aFunction->upsertMigration();
            }

            //
            if (self::getSettingValue('plpgsql_check.active')) {
                // let's check stored functions
                Database::checkAllStoredFunctionsByPlpgsqlCheck($aFunctions);
                // says rollback and throws exception if check fails
            }

            // deploying triggers
            foreach ($aTriggers as $aTrigger) {
                $aTrigger->applyObject();
                $aTrigger->upsertMigration();
            }

            //
            foreach (array('queries_after') as $sForwardableIndex) {

                // forwarding queries - deploying diff
                foreach ($aForwarded[$sForwardableIndex] as $iForwardOrder => $oForwardableObject) {
                    $oForwardableObject->forward();
                    $oForwardableObject->upsertMigration();
                }

                // deploying queries (imitation)
                // only upsert migration, user deploys query by himself
                foreach ($aNonForwarded[$sForwardableIndex] as $oNonForwardableObject) {
                    $oNonForwardableObject->applyObject();
                    $oNonForwardableObject->upsertMigration();
                }

            }

            // say commit
            self::$oDB->commit();

        } catch (Exception $oException) {
            // throw further
            throw $oException;
        }

    }

    /**
     * Makes object definition
     *
     * @param string schema name
     * @param string object index
     * @param string object name
     *
     * @return Object SQL definition (pg_dump output for tables and types)
     */

    public static function define($sSchemaName, $sObjectIndex, $sObjectName)
    {
        // make object
        $oObject = DatabaseObject::make(
            self::$sDatabase,
            $sSchemaName,
            $sObjectIndex,
            $sObjectName,
            ''
        );

        return $oObject->define();
    }

    /**
     * Makes object description
     *
     * @param string schema name
     * @param string object index
     * @param string object name
     *
     * @return Object description (psql output for tables and types)
     */

    public static function describe($sSchemaName, $sObjectIndex, $sObjectName)
    {
        // make object
        $oObject = DatabaseObject::make(
            self::$sDatabase,
            $sSchemaName,
            $sObjectIndex,
            $sObjectName,
            ''
        );

        return $oObject->describe();
    }

    /**
     * Drops object
     *
     * @param string schema name
     * @param string object index
     * @param string object name
     *
     * @return boolean true if table was dropped
     */

    public static function drop($sSchemaName, $sObjectIndex, $sObjectName)
    {
        // make object
        $oObject = DatabaseObject::make(
            self::$sDatabase,
            $sSchemaName,
            $sObjectIndex,
            $sObjectName,
            ''
        );

        return $oObject->drop();
    }

    /**
     * Prepares archive (tar.gz) with definitions of schema objects
     *
     * @return array file_name and error
     */

    public static function createDefinitionsFile()
    {
        $sDatabaseWithTimestamp = self::$sDatabase . '_' . date("Y_m_d_H_i_s");

        // dir to store files
        $sBaseDirectory = sys_get_temp_dir() . '/' . $sDatabaseWithTimestamp . '/';
        if (! file_exists($sBaseDirectory)) {
            mkdir($sBaseDirectory, 0755, true);
        }

        // all schemas in database
        $aSchemas = self::getSchemas();
        $sExcludeRegexpShowObjectsNotInGit = self::getSettingValue('not_in_git.exclude_regexp', '');

        // for each schema
        foreach ($aSchemas as $sSchema) {

            // for each object type - index
            foreach (self::getObjectsIndexes() as $sObjectIndex) {

                // ALL objects in database
                $aObjects = Database::getObjectsAsVirtualFiles($sSchema, $sObjectIndex);

                if ($sExcludeRegexpShowObjectsNotInGit) {
                    // filter objects using not_in_git.exclude_regexp
                    foreach ($aObjects as $sKey => $aObjectData) {
                        $sObjectNameWithSchema = $sSchema . '.' . $sKey;
                        if (preg_match('~' . $sExcludeRegexpShowObjectsNotInGit . '~uixs', $sObjectNameWithSchema)) {
                            // skip
                            unset($aObjects[$sKey]);
                        }
                    }
                }

                // removing objects under git (file exists)
                foreach ($aObjects as $sObjectName => $aObject) {
                    $sObjectFileName = self::getAbsoluteFileName(self::makeRelativeFileName($sSchema, $sObjectIndex, $sObjectName));
                    if (file_exists($sObjectFileName)) {
                        unset($aObjects[$sObjectName]);
                    }
                }

                // iterate through objects not in git
                foreach ($aObjects as $sObjectName => $aObject) {
                    // get definition
                    $aDefinition = self::define($sSchema, $sObjectIndex, $sObjectName);
                    if ($sDefinition = $aDefinition['definition']) {
                        // file for definition
                        $sFileName = $sBaseDirectory . self::makeRelativeFileName($sSchema, $sObjectIndex, $sObjectName);
                        // dir of this file
                        $sDirectoryName = dirname($sFileName);
                        if (! file_exists($sDirectoryName)) {
                            mkdir($sDirectoryName, 0755, true);
                        }
                        // save it
                        file_put_contents($sFileName, $sDefinition);
                    } else if ($sError = $aDefinition['error']) {
                        // error
                        return array(
                            'file_name' => '',
                            'error' => $sError,
                        );
                    } else {
                        // strange situation: no definition, no error
                        // let's skip it
                        ;
                    }
                }
            }
        }

        // now we gonna pack files
        $sFileName = sys_get_temp_dir() . '/' . $sDatabaseWithTimestamp . '.tar.gz';

        $sError = '';
        self::callExternalTool('tar.gz', array($sFileName, $sBaseDirectory), $sError);

        // remove dir
        self::callExternalTool('rm', array('-rf', $sBaseDirectory), $sError);

        return array(
            'file_name' => $sFileName,
            'error' => $sError,
        );
    }

    /**
     * Calls external utility
     *
     * @return string output
     */

    public static function callExternalTool($sTool, $aCmd, & $sError = '')
    {
        $aAdditionalCmd = array();

        if ($sTool == 'psql' or $sTool == 'pg_dump') {
            // to get server version and connection params
            $aCredentials = DBRepository::getDBCredentials();

            // path from settings
            $sPath = self::getSettingValue('paths.pg_bin', '/usr/lib/postgresql/%v/bin/');
            // replace %v for version
            $sPath = str_replace("%v", $aCredentials['version'], $sPath);
            // command to be executed = path + bin
            $sCmd = $sPath . $sTool;

            // credentials are needed
            $aAdditionalCmd = array
            (
                '-U', $aCredentials['user_name'],
                '-h', $aCredentials['host'],
                '-p', $aCredentials['port'],
                $aCredentials['db_name']
            );
        } else if ($sTool == 'tar.gz') {
            $sCmd = 'tar';
            // files and dirs
            $aAdditionalCmd = array(
                $aCmd[0], // target file
                '-C',     // working dir:
                $aCmd[1], //
                '.'       //
            );
            // options
            $aCmd = array('zcvfP');
        } else {
            $sCmd = $sTool;
        }

        // merge command and its arguments
        $aCmd = array_merge(
            array($sCmd),
            $aCmd,
            $aAdditionalCmd
        );

        // make process
        $oBuilder = new ProcessBuilder($aCmd);
        $oProcess = $oBuilder->getProcess();
        $oProcess->run();
        $sError = $oProcess->getErrorOutput();
        return $oProcess->getOutput();
    }

    /**
     * Gets initial messages
     *
     * @return array messages
     */

    public static function getInitialMessages()
    {
        return Database::getPlpgsqlCheckStatus();
    }

}


