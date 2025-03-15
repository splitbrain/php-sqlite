# PHP SQLite Helper

A lightweight PHP library for working with SQLite databases with automatic schema migrations.

This does not replace a full ORM like Doctrine, but it's also like a million times lighter.

## Features

- Simple helpers for common SQLite operations
- Automatic schema migrations
- Prepared statement handling
- Enables WAL journal mode, foreign key support and exceptions by default

## Installation

```bash
composer require splitbrain/php-sqlite
```

## Basic Usage

```php
use splitbrain\phpsqlite\SQLite;

// Initialize with database file and schema directory
$db = new SQLite('path/to/database.sqlite', 'path/to/migrations');

// Apply any pending migrations
$db->migrate();

// Query data
$contacts = $db->queryAll("SELECT * FROM contacts WHERE name LIKE ?", "%John%");

// Get a single record
$contact = $db->queryRecord("SELECT * FROM contacts WHERE contact_id = ?", 42);

// Get a single value
$count = $db->queryValue("SELECT COUNT(*) FROM contacts");

// Insert or update data
$newContact = $db->saveRecord("contacts", [
    "name" => "John Doe",
    "email" => "john@example.com"
]);

// Execute statements
$db->exec("DELETE FROM contacts WHERE contact_id = ?", 42);
```

Check the [SQLite class](src/SQLite.php) for more methods.

## Schema Migrations

Place your migration files in the schema directory with filenames like:

- `0001.sql` - Initial schema
- `0002.sql` - Add new tables
- `0003.sql` - Add sample data

The library will automatically apply migrations in order when you call `$db->migrate()`.

A table called `opts` is automatically created to track applied migrations. You can also use this table to store
configuration values.

