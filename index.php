<?php
/**
 * todolist2mysql
 * 
 * This script reads a TDL file and inserts the tasks into a MySQL database.
 * 
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to get database connection
function getDatabaseConnection($dbName) {
    $host = 'localhost';
    $user = 'root';  // Adjust if different
    $pass = '';      // Adjust if different
    $charset = 'utf8mb4';

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
    // Log the task data
    error_log("Task data: " . print_r($task, true));

    // Ensure title is not null
    $title = $task['TITLE'] ?? $task['title'] ?? null;
    if ($title === null) {
        error_log("Task without title: " . print_r($task, true));
        return null; // Skip tasks without a title
    }

    $sql = "INSERT INTO tasks (title, status, priority, percentdone, startdate, duedate, creationdate, lastmod, comments) 
            VALUES (:title, :status, :priority, :percentdone, :startdate, :duedate, :creationdate, :lastmod, :comments)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
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
    return $pdo->lastInsertId();
}

// Function to insert category into database
function insertCategory($pdo, $categoryName) {
    $sql = "INSERT IGNORE INTO categories (name) VALUES (:name)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':name' => $categoryName]);
    return $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM categories WHERE name = " . $pdo->quote($categoryName))->fetchColumn();
}

// Function to link task and category
function linkTaskCategory($pdo, $taskId, $categoryId) {
    $sql = "INSERT IGNORE INTO task_categories (task_id, category_id) VALUES (:task_id, :category_id)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':task_id' => $taskId, ':category_id' => $categoryId]);
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
            error_log("Successfully parsed XML with encoding: " . $encoding);
            return $xml;
        }
        error_log("Failed to parse XML with encoding: " . $encoding);
        foreach(libxml_get_errors() as $error) {
            error_log("XML Parse Error: " . $error->message);
        }
        libxml_clear_errors();
    }
    return false;
}

// Function to process TDL file
function processTDLFile($filePath, $dbName) {
    $content = file_get_contents($filePath);
    if ($content === false) {
        echo "Failed to read the file: $filePath\n";
        return;
    }

    error_log("File size: " . strlen($content) . " bytes");
    error_log("First 100 characters: " . bin2hex(substr($content, 0, 100)));
    
    $xml = parseXML($content);
    
    if ($xml === false) {
        echo "Failed to parse XML. Please check the error log for details.\n";
        return;
    }

    // Log the structure of the XML
    error_log("XML structure: " . print_r($xml, true));

    $pdo = getDatabaseConnection($dbName);

    // Start transaction
    $pdo->beginTransaction();
    
    try {
        $taskCount = 0;
        foreach ($xml->TASK as $task) {
            $taskData = (array)$task;
            // Check if attributes exist, if not, use the task element itself
            if (empty($taskData['@attributes'])) {
                $taskData = (array)$task;
            } else {
                $taskData = (array)$task->attributes();
            }
            $taskId = insertTask($pdo, $taskData);
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
        
        // Commit transaction
        $pdo->commit();
        echo "Data inserted successfully. Total tasks processed: $taskCount\n";
        error_log("Successfully processed file: $filePath. Tasks inserted: $taskCount");
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        echo "Error: " . $e->getMessage() . "\n";
        error_log("Database Error: " . $e->getMessage());
    }
}

// Check if script is run from CLI
if (php_sapi_name() === 'cli') {
    if ($argc != 2) {
        echo "Usage: php index.php <tdl_file>\n";
        exit(1);
    }

    $tdlFile = $argv[1];
    $dbName = pathinfo($tdlFile, PATHINFO_FILENAME);

    echo "Processing file: $tdlFile\n";
    echo "Using database: $dbName\n";

    processTDLFile($tdlFile, $dbName);
} else {
    // Web interface code
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tdlFile'])) {
        if ($_FILES['tdlFile']['error'] === UPLOAD_ERR_OK) {
            $dbName = pathinfo($_FILES['tdlFile']['name'], PATHINFO_FILENAME);
            processTDLFile($_FILES['tdlFile']['tmp_name'], $dbName);
        } else {
            echo "File upload failed with error code: " . $_FILES['tdlFile']['error'];
            error_log("File upload failed: " . $_FILES['tdlFile']['name'] . ". Error code: " . $_FILES['tdlFile']['error']);
        }
    }
}
if (php_sapi_name() === 'cli') {
    exit(0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TDL File Upload</title>
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
    <h1>Upload TDL File</h1>
    <div id="drop_zone">
        <p>Drag and drop a .tdl file here</p>
        <form id="upload_form" method="post" enctype="multipart/form-data">
            <input type="file" name="tdlFile" id="file_input" style="display: none;" accept=".tdl">
        </form>
    </div>

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
