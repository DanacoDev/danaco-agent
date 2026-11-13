---
name: sqlite_helper
description: Creates or changes a SQLite file in database/. Pass the filename. drop removes one table. Do not use sqlite3, run_command, or run_python.
version: 1.0.0
---

database is the filename or the path, such as link_database.db or database/link_database.db. action, or command, is count, inspect, insert, query, drop, ensure, execute, or init.

Create workflow: always check the database and table first. If either already exists, do not recreate it. The schema is returned so you can compare columns.

ensure: {"database":"link_database.sqlite","table":"links","columns":["url TEXT","title TEXT"]} or one CREATE TABLE statement in sql.
When the table exists, read columns, missing_columns, and extra_columns. Add a column with execute and ALTER TABLE links ADD COLUMN name TYPE. Remove one with ALTER TABLE links DROP COLUMN name. Do not CREATE TABLE again.
count: {"database":"link_database.db"} returns each table and the total. Add table to count one table.
inspect: read schemas, indexes, row counts, and sample rows. database all lists every database file in the folder.
query: run a read-only SELECT, WITH, or PRAGMA statement with params.
insert: add a row with table and values.
drop: {"database":"link_database.db","table":"links"} deletes that table. if_exists true leaves a missing table alone. A table drop is approved by the user through the main agent.
execute: run a parameterized write. CREATE TABLE is checked first and is not run when that table exists. DROP TABLE is the drop action.
init: only for a preset schema the user already named. For any other file, use ensure with that same filename, table, and columns. Do not invent a filename.

Every existing database is snapshotted in the project backup vault before a write. Do not use the sqlite3 CLI. Do not open the file from Python. Do not ask anyone to open the database file.
