# SchemaManage plugin for CakePHP

This plugin uses DBDiff to generate schema diffs in SQL, as an alternative to the Migrations plugin as this workflow seems more intuitive for source control.  Plus, the file format used is plain SQL so diffs are very easy to read.  

Unless the `--dry-run` option is added, files are output to `ROOT/config/SchemaDiffs/<connection_after>/*.sql`.

There are 3 commands available:

1. diff - diff between two schemas.  Note that the order of connections in the command is reversed, compared to standard DBDiff - but it makes more sense.

    `bin/cake schema_manage.diff <diff_name> <connection_before> <connection_after> [--dry-run]`
2. snapshot - snapshot a schema

    `bin/cake schema_manage.snapshot <snapshot_name> <connection_name> [--dry-run]`
3. diff_latest - get a diff from the schema constructed by all the diff files (i.e., the "working tree")

    `bin/cake schema_manage.diff_latest <diff_name> <connection_name> [--dry-run]`



## Installation

You can install this plugin into your CakePHP application using [composer](https://getcomposer.org).

The recommended way to install composer packages is:

```
composer require rkaiser0324/schema-manage
```
