<?php
/**
 * todolist2csv
 * 
 * This script processes a .tdl file and imports the tasks into a MySQL database.
 * 
 * The script can be run from the command line or as a web interface.
 * 
 * Command line usage:
 * php index.php <tdl_file>
 * 
 * Web interface:
 * Upload a .tdl file using the web interface.
 * 
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
const DEBUG = false;
const VERSION = '24.10.06.1353';

// Function to log messages
function logMessage($message) {
    error_log($message);
}

// Function to create database if it doesn't exist and delete if exists
function createDatabaseIfNotExists($dbName) {
    $host = 'localhost';
    $user = 'root';  // Adjust if different
    $pass = '';      // Adjust if different

    try {
        $pdo = new PDO("mysql:host=$host", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        //FIXME: delete database if exists
        $sql = "DROP DATABASE IF EXISTS `$dbName`";
        $pdo->exec($sql);
        logMessage("Database $dbName deleted successfully or does not exist.");

        // Create database if it doesn't exist
        $sql = "CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $pdo->exec($sql);
        logMessage("Database $dbName created successfully or already exists.");

        // Use the newly created database
        $pdo->exec("USE `$dbName`");

        // Import the schema from todolist.sql
        $schema = file_get_contents('todolist.sql');
        if ($schema === false) {
            throw new Exception("Failed to read todolist.sql");
        }
        $pdo->exec($schema);
        logMessage("Schema imported successfully.");

    } catch (PDOException $e) {
        die("DB ERROR: " . $e->getMessage());
    } catch (Exception $e) {
        die("ERROR: " . $e->getMessage());
    }
}

// Function to get database connection
function getDatabaseConnection($dbName) {
    $host = 'localhost';
    $user = 'root';  // Adjust if different
    $pass = '';      // Adjust if different
    $charset = 'utf8mb4';

    createDatabaseIfNotExists($dbName);

    $dsn = "mysql:host=$host;dbname=$dbName;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
}

// Function to insert task into database
function insertTask($pdo, $task) {
    if (DEBUG) logMessage("Inserting task: " . print_r($task, true));

    // Ensure title is not null
    $title = $task['TITLE'] ?? $task['title'] ?? null;
    if ($title === null) {
        logMessage("ERROR: Task without title: " . print_r($task, true));
        return null; // Skip tasks without a title
    }

    $sql = "INSERT INTO tasks (title, status, priority, percentdone, startdate, duedate, creationdate, lastmod, comments) 
            VALUES (:title, :status, :priority, :percentdone, :startdate, :duedate, :creationdate, :lastmod, :comments)";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':title' => $title,
        ':status' => $task['STATUS'] ?? $task['status'] ?? null,
        ':priority' => $task['PRIORITY'] ?? $task['priority'] ?? null,
        ':percentdone' => $task['PERCENTDONE'] ?? $task['percentdone'] ?? null,
        ':startdate' => $task['STARTDATE'] ?? $task['startdate'] ?? null,
        ':duedate' => $task['DUEDATE'] ?? $task['duedate'] ?? null,
        ':creationdate' => $task['CREATIONDATE'] ?? $task['creationdate'] ?? null,
        ':lastmod' => $task['LASTMOD'] ?? $task['lastmod'] ?? null,
        ':comments' => $task['COMMENTS'] ?? $task['comments'] ?? null
    ]);

    if ($result) {
        $taskId = $pdo->lastInsertId();
        logMessage("Task inserted successfully. ID: $taskId");
        return $taskId;
    } else {
        logMessage("Failed to insert task: " . print_r($stmt->errorInfo(), true));
        return null;
    }
}

// Function to insert category into database
function insertCategory($pdo, $categoryName) {
    $sql = "INSERT IGNORE INTO categories (name) VALUES (:name)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':name' => $categoryName]);
    $categoryId = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM categories WHERE name = " . $pdo->quote($categoryName))->fetchColumn();
    logMessage("Category inserted or retrieved. ID: $categoryId, Name: $categoryName");
    return $categoryId;
}

// Function to link task and category
function linkTaskCategory($pdo, $taskId, $categoryId) {
    $sql = "INSERT IGNORE INTO task_categories (task_id, category_id) VALUES (:task_id, :category_id)";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([':task_id' => $taskId, ':category_id' => $categoryId]);
    if ($result) {
        logMessage("Task $taskId linked with category $categoryId");
    } else {
        logMessage("Failed to link task $taskId with category $categoryId: " . print_r($stmt->errorInfo(), true));
    }
}

// Function to attempt XML parsing with different encodings
function parseXML($content) {
    $encodings = ['UTF-8', 'UTF-16LE', 'UTF-16BE'];
    foreach ($encodings as $encoding) {
        $xmlContent = $content;
        if ($encoding !== 'UTF-8') {
            $xmlContent = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        
        // Remove BOM if present
        $bom = pack('H*','EFBBBF');
        $xmlContent = preg_replace("/^$bom/", '', $xmlContent);
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        if ($xml !== false) {
            logMessage("Successfully parsed XML with encoding: $encoding");
            return $xml;
        }
        logMessage("Failed to parse XML with encoding: $encoding");
        foreach(libxml_get_errors() as $error) {
            logMessage("XML Parse Error: " . $error->message);
        }
        libxml_clear_errors();
    }
    return false;
}

// Function to process TDL file
function processTDLFile($filePath, $dbName) {
    $content = file_get_contents($filePath);
    if ($content === false) {
        logMessage("Failed to read the file: $filePath");
        return;
    }

    if (DEBUG) logMessage("File size: " . strlen($content) . " bytes");
    if (DEBUG) logMessage("First 1000 characters of file content:");
    if (DEBUG) logMessage(substr($content, 0, 1000));
    
    $xml = parseXML($content);
    
    if ($xml === false) {
        if (DEBUG) logMessage("Failed to parse XML. Please check the error log for details.");
        return;
    }

    logMessage("XML structure:");
    if (DEBUG) logMessage(print_r($xml, true));

    // Log the names of all child elements of the root
    logMessage("Child elements of root:");
    foreach ($xml->children() as $child) {
        if (DEBUG) logMessage($child->getName());
    }

    // Log the number of TASK elements found
    $tasks = $xml->xpath('//TASK');
    logMessage("Number of TASK elements found: " . count($tasks));

    // Get database connection
    $pdo = getDatabaseConnection($dbName);

    // Start transaction
    $pdo->beginTransaction();
    
    // Process each TASK element
    logMessage ("Processing TASKS - START");
    try {
        $taskCount = 0;
        foreach ($xml->TASK as $task) {
            logMessage("Processing task (taskCount=$taskCount)\r\n");
            //logMessage(print_r($task, true));

            $taskData = (array)$task;
            // Check if attributes exist, if not, use the task element itself
            if (empty($taskData['@attributes'])) {
                if (DEBUG) echo "No found attributes\r\n";
                $taskData = (array)$task;
            } else {
                if (DEBUG) echo "Found attributes\r\n";
                $taskData = array_merge(array(), (array)$taskData['@attributes']);//FIXME - check if this is correct
            }
            // Insert task into database
            $taskId = insertTask($pdo, $taskData);

            // If the taskId is not null, then process categories
            if ($taskId !== null) {
                $taskCount++;
               
                // Handle categories
                if (isset($task->CATEGORY)) {
                    foreach ($task->CATEGORY as $category) {
                        $categoryId = insertCategory($pdo, (string)$category);
                        linkTaskCategory($pdo, $taskId, $categoryId);
                    }
                }
            }
        }
        logMessage ("Processing TASKS - END");
        
        // Commit transaction
        $pdo->commit();
        logMessage("Data inserted successfully. Total tasks processed: $taskCount");
        logMessage("----");
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        logMessage("Error: " . $e->getMessage());
    }
    
    return 0; // return success to show the user that the file was processed successfully
}

// Check if script is run from CLI
if (php_sapi_name() === 'cli') {
    // CLI arguments check
    if ($argc != 2) {
        echo "Usage: php index.php <tdl_file>\n";
        exit(1);
    }

    // CLI processing code from CLI arguments
    $tdlFile = $argv[1];
    $dbName = pathinfo($tdlFile, PATHINFO_FILENAME);
    $dbName = $dbName.".tdl"; // TODO: Create the database wih the same name as the tdl file


    logMessage("Processing file: $tdlFile");
    logMessage("Using database: $dbName");

    processTDLFile($tdlFile, $dbName);
} else {
    // Web interface code
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tdlFile'])) {
        if ($_FILES['tdlFile']['error'] === UPLOAD_ERR_OK) {
            $dbName = pathinfo($_FILES['tdlFile']['name'], PATHINFO_FILENAME);
            $dbName = $dbName.".tdl"; // TODO: Create the database wih the same name as the tdl file
            $result = processTDLFile($_FILES['tdlFile']['tmp_name'], $dbName);
            if ($result === 0) {
                echo "<center>";
                echo "<code>✅ File [$dbName] uploaded successfully.";
                echo "</center>";
            }else{
                echo "<center>";
                echo "<code>❌ File [$dbName] failed to upload.";
                echo "</center>";
            }
        } else {
            logMessage("File upload failed with error code: " . $_FILES['tdlFile']['error']);
        }
    }
}

// DONT REMOVE - if we are in CLI then exit here to prevent the rest of HTML from being output in CLI mode
if (php_sapi_name() === 'cli') {
    die(0);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>todolist2csv</title>
    <style>
        #drop_zone {
            border: 2px dashed #ccc;
            width: 300px;
            height: 200px;
            padding: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
    <center>

<?php
// Display success message if file was uploaded
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tdlFile'])) {
    $phpmyadmin='http://localhost/phpmyadmin/index.php?route=/sql&server=1&db='.$dbName.'&table=tasks&pos=0';
    echo '<center><br><a href="'.$phpmyadmin.'" target="_blank">View Database</a></center>';
}
?>

    <h1>todolist2csv</h1>
    <div id="drop_zone">
        <p>Drag and drop a .tdl file here</p>
        <form id="upload_form" method="post" enctype="multipart/form-data">
            <input type="file" name="tdlFile" id="file_input" style="display: none;" accept=".tdl">
        </form>
    </div>
    </center>

    <script>
        var dropZone = document.getElementById('drop_zone');
        var fileInput = document.getElementById('file_input');

        dropZone.ondragover = function(e) {
            e.preventDefault();
            this.style.background = "#e1e7f0";
        }

        dropZone.ondragleave = function(e) {
            this.style.background = "#fff";
        }

        dropZone.ondrop = function(e) {
            e.preventDefault();
            this.style.background = "#fff";
            fileInput.files = e.dataTransfer.files;
            document.getElementById('upload_form').submit();
        }

        dropZone.onclick = function() {
            fileInput.click();
        }

        fileInput.onchange = function() {
            document.getElementById('upload_form').submit();
        }
    </script>
</body>
</html>
