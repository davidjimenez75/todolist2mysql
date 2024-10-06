<?php
// Database connection details
$host = 'localhost';
$db   = 'todolist';
$user = 'todolist';
$pass = 'todolist';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// Function to insert task into database
function insertTask($pdo, $task) {
    $sql = "INSERT INTO tasks (title, status, priority, percentdone, startdate, duedate, creationdate, lastmod, comments) 
            VALUES (:title, :status, :priority, :percentdone, :startdate, :duedate, :creationdate, :lastmod, :comments)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':title' => $task['TITLE'],
        ':status' => $task['STATUS'] ?? null,
        ':priority' => $task['PRIORITY'] ?? null,
        ':percentdone' => $task['PERCENTDONE'] ?? null,
        ':startdate' => $task['STARTDATE'] ?? null,
        ':duedate' => $task['DUEDATE'] ?? null,
        ':creationdate' => $task['CREATIONDATE'] ?? null,
        ':lastmod' => $task['LASTMOD'] ?? null,
        ':comments' => $task['COMMENTS'] ?? null
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


// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tdlFile'])) {
    if ($_FILES['tdlFile']['error'] === UPLOAD_ERR_OK) {
        // Open the file with UTF-16LE encoding
        $file = fopen($_FILES['tdlFile']['tmp_name'], 'r');
        if ($file === false) {
            echo "Failed to open the file.";
            error_log("Failed to open uploaded file: " . $_FILES['tdlFile']['name']);
        } else {
            // Read the file content
            $content = fread($file, filesize($_FILES['tdlFile']['tmp_name']));
            fclose($file);

            // Remove BOM if present
            $bom = pack('H*','FFFE');
            $content = preg_replace("/^$bom/", '', $content);

            // Convert from UTF-16LE to UTF-8
            $xmlContent = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');

            // Load and parse XML
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xmlContent);
            
            if ($xml === false) {
                echo "Failed to parse XML. Errors:<br>";
                foreach(libxml_get_errors() as $error) {
                    echo $error->message . "<br>";
                    error_log("XML Parse Error: " . $error->message);
                }
            } else {
                // Start transaction
                $pdo->beginTransaction();
                
                try {
                    $taskCount = 0;
                    foreach ($xml->TASK as $task) {
                        $taskId = insertTask($pdo, (array)$task->attributes());
                        $taskCount++;
                        
                        // Handle categories
                        if (isset($task->CATEGORY)) {
                            foreach ($task->CATEGORY as $category) {
                                $categoryId = insertCategory($pdo, (string)$category);
                                linkTaskCategory($pdo, $taskId, $categoryId);
                            }
                        }
                    }
                    
                    // Commit transaction
                    $pdo->commit();
                    echo "Data inserted successfully. Total tasks processed: " . $taskCount;
                    error_log("Successfully processed file: " . $_FILES['tdlFile']['name'] . ". Tasks inserted: " . $taskCount);
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $pdo->rollBack();
                    echo "Error: " . $e->getMessage();
                    error_log("Database Error: " . $e->getMessage());
                }
            }
        }
    } else {
        echo "File upload failed with error code: " . $_FILES['tdlFile']['error'];
        error_log("File upload failed: " . $_FILES['tdlFile']['name'] . ". Error code: " . $_FILES['tdlFile']['error']);
    }
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
