# todolist2mysql

Sandbox for testing the import of Todolist (*.tdl) files to MySQL.


# MySQL Connection

Tested on XAMPP for Windows (dont use in production or public servers):

```
    $host = 'localhost';
    $user = 'root';  // Adjust if different
    $pass = '';      // Adjust if different
```


## todolist.sql

The SQL scheme that will be used to create new databases.

The new databases will be created with the exact name of the file uploaded "filename.tdl".

IMPORTANT: If the database name already exists it will de DELETED!


## Usage

From CLI:

```
php index.php filename.tdl
```

From web:

```
Drag and drog a *.tdl file
```


## TO-DO

- [ ] Import root tasks comments
- [ ] Import child taks (now only import root folder tasks)