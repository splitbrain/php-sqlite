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

}
