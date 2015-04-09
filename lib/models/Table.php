<?php

class Table extends DatabaseObject implements IForwardable
{

    public function objectExists()
    {
        return (boolean)self::$oDB->selectField("
            SELECT 1
                FROM pg_tables
                WHERE   schemaname = ?w AND
                        tablename = ?w
        ",
            $this->sSchemaName,
            $this->sObjectName
        );
    }

    public function getObjectDependencies()
    {
        if (! $this->signatureChanged()) {
            return array();
        }
        return self::$oDB->selectTable("
            SELECT  database_name,
                    schema_name AS dependency_schema_name,
                    object_index AS dependency_object_index,
                    object_name AS dependency_object_name,
                    additional_sql
                FROM postgresql_deployer.get_table_dependent_functions(?w, ?w, ?w);
        ",
            $this->sDatabaseName,
            $this->sSchemaName,
            $this->sObjectName
        );

    }

    public function signatureChanged()
    {
        return  $this->oDiff and // diff can be null for objects not in git
                $this->oDiff->tableSignatureChanged();
    }

    public function applyObject()
    {
        if (self::$bImitate) {
            return;
        }

        DBRepository::setLastAppliedObject($this);
    }

    public function canBeForwarded()
    {
        return $this->oDiff->canBeForwarded();
    }

    public function forward()
    {
        if (self::$bImitate) {
            return;
        }

        $aDroppedFunctions = array();

        DBRepository::setLastAppliedObject($this);

        $sForwardStatements = $this->getDiff()->getForwardStatements("\n");
        $sForwardStatements = self::stripTransaction($sForwardStatements);

        if ($this->signatureChanged()) {
            $aDroppedFunctionsRaw = self::$oDB->selectIndexedTable("
                SELECT  -- index in result set
                        -- also see DatabaseObject::__toString
                        schema_name || '/' || object_index || '/' || object_name,
                        -- other
                        *
                    FROM postgresql_deployer.get_table_dependent_functions(?w, ?w, ?w);
            ",
                $this->sDatabaseName,
                $this->sSchemaName,
                $this->sObjectName
            );

            foreach ($aDroppedFunctionsRaw as $sIndex => $aDroppedFunctionRaw) {
                $aDroppedFunctions[$sIndex] = DatabaseObject::make(
                    $aDroppedFunctionRaw['database_name'],
                    $aDroppedFunctionRaw['schema_name'],
                    $aDroppedFunctionRaw['object_index'],
                    $aDroppedFunctionRaw['object_name'],
                    '1'
                );
            };
        }

        if ($sForwardStatements) {
            self::$oDB->query($sForwardStatements);
        }

        return $aDroppedFunctions;
    }

    public function hasChanged($sCurrentHash)
    {
        return  ! isset(self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName]) or
                self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName] != $sCurrentHash;
    }

    public function isDescribable ()
    {
        return true;
    }

    public function isDefinable ()
    {
        return true;
    }

    public function isDroppable ()
    {
        return true;
    }

    public function define()
    {
        $sError = '';
        $sOutput = DBRepository::callExternalTool(
            'pg_dump',
            array(
                '--schema-only',
                '-t',
                $this->sSchemaName . '.' . $this->sObjectName
            ),
            $sError
        );

        $sOutput = preg_replace("~^--.*~uixm", "", $sOutput);
        $sOutput = preg_replace("~^SET.*~uixm", "", $sOutput);
        $sOutput = preg_replace("~\\n\\n~uixs", "\n", $sOutput);
        $sOutput = preg_replace("~\\n\\n~uixs", "\n", $sOutput);

        $sOutput = trim($sOutput);

        return array(
            'definition' => $sOutput,
            'error' => $sError,
        );
    }

    public function describe()
    {
        $sError = '';
        $sOutput = DBRepository::callExternalTool(
            'psql',
            array('-c\d+ ' . $this->sSchemaName . '.' . $this->sObjectName),
            $sError
        );

        return array(
            'description' => $sOutput,
            'error' => $sError,
        );
    }

    public function drop()
    {
        if ($this->objectExists()) {
            self::$oDB->t()->query("
                DROP TABLE ?.? CASCADE;
            ",
                // this variables were checked in objectExists so we can drop nothing but pointed table
                $this->sSchemaName,
                $this->sObjectName
            );
        } else {
            throw new Exception("There is no table.");
        }

        return true;
    }

}


