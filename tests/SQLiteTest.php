<?php

namespace splitbrain\phpsqlite\tests;

use PHPUnit\Framework\TestCase;
use splitbrain\phpsqlite\SQLite;

class SQLiteTest extends TestCase
{
    /**
     * @var string remeber files to clean up on tearDown
     */
    protected $cleanup = [];

    /** @inheritdoc */
    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Return a temporary file location
     *
     * @return string
     */
    protected function init($schema, $migrate = true): SQLite
    {
        $dbFile = tempnam(sys_get_temp_dir(), 'sqlite_test_');
        unlink($dbFile); // Remove the file, SQLite will create it
        $this->cleanup[] = $dbFile;

        $schemadir = __DIR__ . '/migrations/' . $schema;

        $sqlite = new SQLite($dbFile, $schemadir);
        if ($migrate) {
            $sqlite->migrate();
        }
        return $sqlite;
    }


    public function testMigrate()
    {
        $db = $this->init('contacts', false);
        $this->assertEquals(0, $db->currentDbVersion()); // this will create the opt table
        $db->migrate();
        $this->assertEquals(3, $db->currentDbVersion());
    }


    public function testPdo()
    {
        $db = $this->init('contacts', false);
        $pdo = $db->pdo();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testExec()
    {
        $db = $this->init('contacts');

        // Test INSERT
        $id = $db->exec("INSERT INTO contacts (name) VALUES (?)", ["Test Name"]);
        $this->assertEquals(3, $id);

        // Test UPDATE
        $affected = $db->exec("UPDATE contacts SET comment = ?", ["Test Comment"]);
        $this->assertEquals(3, $affected);

        // Test DELETE
        $affected = $db->exec("DELETE FROM contacts WHERE contact_id = ?", [1]);
        $this->assertEquals(1, $affected);
    }

    public function testQueryAll()
    {
        $db = $this->init('contacts');


        // Query all records
        $results = $db->queryAll("SELECT * FROM contacts ORDER BY contact_id");

        $this->assertCount(2, $results);
        $this->assertEquals("Contact 1", $results[0]['name']);
        $this->assertEquals("Contact 2", $results[1]['name']);
    }

    public function testQueryRecord()
    {
        $db = $this->init('contacts');

        // Query single record
        $record = $db->queryRecord("SELECT * FROM contacts WHERE contact_id = ?", 1);

        $this->assertIsArray($record);
        $this->assertEquals("Contact 1", $record['name']);

        // Query non-existent record
        $nonExistent = $db->queryRecord("SELECT * FROM contacts WHERE contact_id = ?", [999]);
        $this->assertNull($nonExistent);
    }

    public function testSaveRecord()
    {
        $db = $this->init('contacts');

        // Test INSERT
        $newrecord = $db->saveRecord("contacts", ["name" => "Save Test"]);
        $this->assertEquals(['contact_id' => 3, 'name' => 'Save Test', 'comment' => null], $newrecord);
        $record = $db->queryRecord("SELECT * FROM contacts WHERE contact_id = ?", [3]);
        $this->assertEquals("Save Test", $record['name']);

        // Test REPLACE
        $newrecord = $db->saveRecord("contacts", ["contact_id" => 1, "name" => "Replaced"]);
        $this->assertEquals(['contact_id' => 1, 'name' => 'Replaced', 'comment' => null], $newrecord);
        $record = $db->queryRecord("SELECT * FROM contacts WHERE contact_id = ?", [1]);
        $this->assertEquals("Replaced", $record['name']);

        // Test IGNORE
        $newrecord = $db->saveRecord("contacts", ["contact_id" => 2, "name" => "Ignored"], false);
        $this->assertNull($newrecord);
        $record = $db->queryRecord("SELECT * FROM contacts WHERE contact_id = ?", [2]);
        $this->assertEquals("Contact 2", $record['name']);
    }

    public function testQueryValue()
    {
        $db = $this->init('contacts');

        // Query a single value
        $value = $db->queryValue("SELECT name FROM contacts WHERE contact_id = ?", [1]);
        $this->assertEquals("Contact 1", $value);

        // Query non-existent value
        $nonExistent = $db->queryValue("SELECT name FROM contacts WHERE contact_id = ?", [999]);
        $this->assertNull($nonExistent);
    }

    public function testQueryKeyValueList()
    {
        $db = $this->init('contacts');

        // Test with valid query returning key-value pairs
        $result = $db->queryKeyValueList("SELECT name, comment FROM contacts ORDER BY contact_id");
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals([
            "Contact 1" => "in both groups",
            "Contact 2" => "in group 2",
        ], $result);

        // Test with empty result
        $empty = $db->queryKeyValueList("SELECT contact_id, name FROM contacts WHERE contact_id > 999");
        $this->assertIsArray($empty);
        $this->assertEmpty($empty);

        // Test with invalid query (too many columns)
        $this->expectException(\RuntimeException::class);
        $db->queryKeyValueList("SELECT * FROM contacts");
    }

    public function testQueryValueList()
    {
        $db = $this->init('contacts');

        // Test with valid query returning single column
        $result = $db->queryValueList("SELECT name FROM contacts ORDER BY contact_id");
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(["Contact 1", "Contact 2"], $result);

        // Test with empty result
        $empty = $db->queryValueList("SELECT name FROM contacts WHERE contact_id > 999");
        $this->assertIsArray($empty);
        $this->assertEmpty($empty);

        // Test with invalid query (too many columns)
        $this->expectException(\RuntimeException::class);
        $db->queryValueList("SELECT contact_id, name FROM contacts");
    }

    public function testGetOpt()
    {
        $db = $this->init('contacts');

        // Test getting existing value (dbversion is set during migration)
        $version = $db->getOpt('dbversion');
        $this->assertEquals(3, $version);

        // Test getting non-existent value with default
        $nonExistent = $db->getOpt('nonexistent', 'default_value');
        $this->assertEquals('default_value', $nonExistent);

        // Test getting non-existent value without default
        $nullValue = $db->getOpt('nonexistent');
        $this->assertNull($nullValue);
    }

    public function testSetOpt()
    {
        $db = $this->init('contacts');

        // Test setting a new value
        $db->setOpt('test_key', 'test_value');
        $value = $db->getOpt('test_key');
        $this->assertEquals('test_value', $value);

        // Test updating an existing value
        $db->setOpt('test_key', 'updated_value');
        $updatedValue = $db->getOpt('test_key');
        $this->assertEquals('updated_value', $updatedValue);

        // Test setting a numeric value
        $db->setOpt('numeric_key', 42);
        $numericValue = $db->getOpt('numeric_key');
        $this->assertEquals(42, $numericValue);
    }

    public function testQuery()
    {
        $db = $this->init('contacts');

        // Test basic query functionality
        $stmt = $db->query("SELECT * FROM contacts WHERE contact_id = ?", 1);
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals("Contact 1", $result['name']);
        $stmt->closeCursor();

        // Test with array parameter
        $stmt = $db->query("SELECT * FROM contacts WHERE contact_id = ?", [2]);
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals("Contact 2", $result['name']);
        $stmt->closeCursor();

        // Test with multiple parameters
        $stmt = $db->query("SELECT * FROM contacts WHERE name = ? AND comment = ?", "Contact 1", "in both groups");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(1, $result['contact_id']);
        $stmt->closeCursor();
    }

    public function testTransactions()
    {
        $db = $this->init('contacts');
        $pdo = $db->pdo();

        // Test successful transaction
        $pdo->beginTransaction();
        $db->exec("INSERT INTO contacts (name, comment) VALUES (?, ?)", "Transaction Test", "commit");
        $pdo->commit();
        $result = $db->queryRecord("SELECT * FROM contacts WHERE name = ?", "Transaction Test");
        $this->assertNotNull($result);
        $this->assertEquals("commit", $result['comment']);

        // Test rollback
        $pdo->beginTransaction();
        $db->exec("INSERT INTO contacts (name, comment) VALUES (?, ?)", "Rollback Test", "rollback");
        $pdo->rollBack();
        $result = $db->queryRecord("SELECT * FROM contacts WHERE name = ?", "Rollback Test");
        $this->assertNull($result);
    }

    public function testErrorHandling()
    {
        $db = $this->init('contacts');

        // Test syntax error
        $this->expectException(\PDOException::class);
        $db->query("SELECT * FROM non_existent_table");
    }

    public function testConstructorWithPdo()
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $schemadir = __DIR__ . '/migrations/contacts';

        // Create SQLite instance with existing PDO
        $db = new SQLite($pdo, $schemadir);

        // Verify it works by creating a table and inserting data
        $db->exec("CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("INSERT INTO test (name) VALUES (?)", "PDO Test");

        $result = $db->queryRecord("SELECT * FROM test WHERE name = ?", "PDO Test");
        $this->assertNotNull($result);
        $this->assertEquals("PDO Test", $result['name']);
    }

    public function testGetMigrationsToApply()
    {
        $db = $this->init('contacts', false);

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($db);
        $method = $reflection->getMethod('getMigrationsToApply');
        $method->setAccessible(true);

        // Test with current version 0
        $migrations = $method->invoke($db, 0);
        $this->assertCount(3, $migrations);
        $this->assertArrayHasKey(1, $migrations);
        $this->assertArrayHasKey(2, $migrations);
        $this->assertArrayHasKey(3, $migrations);

        // Test with current version 1
        $migrations = $method->invoke($db, 1);
        $this->assertCount(2, $migrations);
        $this->assertArrayHasKey(2, $migrations);
        $this->assertArrayHasKey(3, $migrations);

        // Test with current version 3 (no migrations)
        $migrations = $method->invoke($db, 3);
        $this->assertEmpty($migrations);
    }
}
