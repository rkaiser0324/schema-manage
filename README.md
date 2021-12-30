# SchemaManage plugin for CakePHP 4

This plugin uses [a fork of DBDiff](https://github.com/rkaiser0324/DBDiff) to generate schema diffs in SQL, as an alternative to the [CakePHP Migrations plugin](https://book.cakephp.org/migrations/3/en/index.html) as this workflow seems more intuitive for source control.  Plus, the file format used is plain SQL so diffs are very easy to read.

## Installation

You can install this plugin into your CakePHP application using [composer](https://getcomposer.org).

The recommended way to install composer packages is:

```
composer require rkaiser0324/schema-manage
```

## Usage

Unless the `--dry-run` option is added, files are output to `ROOT/config/SchemaDiffs/<connection_after>/*.sql`.

The following commands are available:

### diff
Get a diff between two schemas.  Note that the order of connections in the command is reversed, compared to standard DBDiff - but it makes more sense.

`bin/cake schema_manage.diff <diff_name> <connection_before> <connection_after> [--dry-run]`

### snapshot
Snapshot a schema.  If `connection_name` is not specified it defaults to "default".

`bin/cake schema_manage.snapshot <snapshot_name> [connection_name] [--dry-run]`

### diff_latest
Get a diff from the schema constructed by all the diff files (i.e., the "working tree"). If `connection_name` is not specified it defaults to "default".

`bin/cake schema_manage.diff_latest <diff_name> [connection_name] [--dry-run]`
