----------------------------------------------------------------------------
-- this script contains:
-- 1) new function postgresql_deployer.get_table_dependent_functions
-- 2) bugfix in postgresql_deployer.get_type_dependent_functions function
--    (see conditions in WHERE statement)


BEGIN;

----------------------------------------------------------------------------
-- get types dependencies

CREATE OR REPLACE FUNCTION postgresql_deployer.get_type_dependent_functions(
    s_database_name varchar,
    s_schema_name varchar,
    s_type_name varchar
)
    RETURNS SETOF postgresql_deployer.database_object AS
$BODY$
DECLARE
    r_func postgresql_deployer.database_object;
    i_type_id integer;
    i_type_array_id integer;
BEGIN

    -- oid of type
    SELECT reltype INTO i_type_id
        FROM pg_class
        WHERE   relname = s_type_name AND
                relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = s_schema_name);

    -- oid of type[]
    SELECT typarray INTO i_type_array_id
        FROM pg_type
        WHERE   typname = s_type_name AND
                typnamespace = (SELECT oid FROM pg_namespace WHERE nspname = s_schema_name);

    -- search for functions
    FOR r_func IN SELECT    s_database_name,
                            n.nspname::varchar AS schema_name,
                            'functions' AS object_type,
                            p.proname::varchar AS function_name,
                            n.nspname  || '.' || p.proname
                                || '(' || pg_catalog.pg_get_function_identity_arguments(p.oid) || ');' AS additional_sql

        FROM pg_catalog.pg_proc AS p

        LEFT JOIN pg_catalog.pg_namespace n ON
            n.oid = p.pronamespace

        WHERE   -- returns type, array of type, setof of type (see also pg_proc.proretset)
                prorettype IN (i_type_id, i_type_array_id) OR
                -- uses type and type[] in arguments
                proargtypes::int[] && array[i_type_id, i_type_array_id] OR
                -- constains type and type[] in DECLARE section
                prosrc ~ ('DECLARE.+?' || s_schema_name || '\.' || s_type_name || '(;|\[).+?BEGIN')

    LOOP

        IF r_func.additional_sql IS NOT NULL THEN
            RETURN NEXT r_func;
        END IF;

    END LOOP;

END
$BODY$
    LANGUAGE plpgsql VOLATILE;

----------------------------------------------------------------------------
-- get tables dependencies

CREATE OR REPLACE FUNCTION postgresql_deployer.get_table_dependent_functions(
    s_database_name varchar,
    s_schema_name varchar,
    s_table_name varchar
)
    RETURNS SETOF postgresql_deployer.database_object AS
$BODY$
DECLARE
    r_func postgresql_deployer.database_object;
    i_table_id integer;
    i_table_array_id integer;
BEGIN

    -- oid of table row type
    SELECT reltype INTO i_table_id
        FROM pg_class
        WHERE   relname = s_table_name AND
                relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = s_schema_name);

    -- oid of table row type[]
    SELECT typarray INTO i_table_array_id
        FROM pg_type
        WHERE   typname = s_table_name AND
                typnamespace = (SELECT oid FROM pg_namespace WHERE nspname = s_schema_name);

    -- search for functions
    FOR r_func IN SELECT    s_database_name,
                            n.nspname::varchar AS schema_name,
                            'functions' AS object_table,
                            p.proname::varchar AS function_name,
                            n.nspname  || '.' || p.proname
                                || '(' || pg_catalog.pg_get_function_identity_arguments(p.oid) || ');' AS additional_sql

        FROM pg_catalog.pg_proc AS p

        LEFT JOIN pg_catalog.pg_namespace n ON
            n.oid = p.pronamespace

        WHERE   -- returns table row, array of table row, setof of table rows (see also pg_proc.proretset)
                prorettype IN (i_table_id, i_table_array_id) OR
                -- uses table row and table row[] in arguments
                proargtypes::int[] && array[i_table_id, i_table_array_id] OR
                -- constains table row type and table row type[] in DECLARE section
                prosrc ~ ('DECLARE.+?' || s_schema_name || '\.' || s_table_name || '(;|\[).+?BEGIN')

    LOOP

        IF r_func.additional_sql IS NOT NULL THEN
            RETURN NEXT r_func;
        END IF;

    END LOOP;

END
$BODY$
    LANGUAGE plpgsql VOLATILE;

COMMIT;

