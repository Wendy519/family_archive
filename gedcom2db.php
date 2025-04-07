<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['host'];
    $dbname = $_POST['dbname'];
    $user = $_POST['user'];
    $pass = $_POST['pass'];
    $uploadDir = __DIR__ . '/uploads/';
    $gedcomFile = $uploadDir . basename($_FILES['gedcom']['name']);
    
    if (!move_uploaded_file($_FILES['gedcom']['tmp_name'], $gedcomFile)) {
        die("<p style='color:red;'>Failed to move uploaded GEDCOM file to ".$uploadDir."</p>");
    } else {
        echo "<p style='color:green;'>GEDCOM file uploaded successfully to $gedcomFile.</p>";
    }

    // Connect to database
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "<p style='color:green;'>Database connection is active.</p>";
    } catch (PDOException $e) {
        die("<p style='color:red;'>Connection failed: " . $e->getMessage() . "</p>");
    }

 // Create tables
 $createTables = <<<SQL
 CREATE TABLE IF NOT EXISTS individuals (
     id VARCHAR(20) PRIMARY KEY,
     name VARCHAR(255) NOT NULL,
     birth_date VARCHAR(50) NULL,
     death_date VARCHAR(50) NULL,
     biography TEXT NULL,
     featured_image VARCHAR(255) NULL
 );
 CREATE TABLE IF NOT EXISTS families (
     id VARCHAR(20),
     husband VARCHAR(20) NULL,
     wife VARCHAR(20) NULL,
     FOREIGN KEY (husband) REFERENCES individuals(id),
     FOREIGN KEY (wife) REFERENCES individuals(id)
 );
 CREATE TABLE IF NOT EXISTS family_children (
     family_id VARCHAR(20) NOT NULL,
     child_id VARCHAR(20) NOT NULL,
     PRIMARY KEY (child_id)
 );
 CREATE TABLE IF NOT EXISTS location_types (
     type VARCHAR(100) PRIMARY KEY
 );
 
 CREATE TABLE IF NOT EXISTS event_types (
     type VARCHAR(100) PRIMARY KEY
 );
 
 CREATE TABLE IF NOT EXISTS artifact_types (
     type VARCHAR(100) PRIMARY KEY
 );
 CREATE TABLE IF NOT EXISTS locations (
     locationID INT AUTO_INCREMENT PRIMARY KEY,
     name VARCHAR(255),
     type VARCHAR(100),
     description TEXT,
     latitude DECIMAL(10,8),
     longitude DECIMAL(11,8),
     featured_image VARCHAR(255),
     FOREIGN KEY (type) REFERENCES location_types(type)
 );
 
 CREATE TABLE IF NOT EXISTS events (
     eventID INT AUTO_INCREMENT PRIMARY KEY,
     type VARCHAR(100),
     date DATE,
     description TEXT,
     locationID INT,
     featured_image VARCHAR(255),
     FOREIGN KEY (locationID) REFERENCES locations(locationID),
     FOREIGN KEY (type) REFERENCES event_types(type)
 );
 
 CREATE TABLE IF NOT EXISTS artifacts (
     artifactID INT AUTO_INCREMENT PRIMARY KEY,
     type VARCHAR(100),
     name VARCHAR(255),
     url TEXT,
     description TEXT,
     locationID INT,
     date DATE,
     featured_image VARCHAR(255),
     FOREIGN KEY (locationID) REFERENCES locations(locationID),
     FOREIGN KEY (type) REFERENCES artifact_types(type)
 );
 CREATE TABLE IF NOT EXISTS event_people (
     eventID INT,
     individualID VARCHAR(20),
     PRIMARY KEY (eventID, individualID),
     FOREIGN KEY (eventID) REFERENCES events(eventID),
     FOREIGN KEY (individualID) REFERENCES individuals(id)
 );
 
 CREATE TABLE IF NOT EXISTS artifact_people (
     artifactID INT,
     individualID VARCHAR(20),
     PRIMARY KEY (artifactID, individualID),
     FOREIGN KEY (artifactID) REFERENCES artifacts(artifactID),
     FOREIGN KEY (individualID) REFERENCES individuals(id)
 );
 SQL;

    try {
        $pdo->exec($createTables);
        echo "<p style='color:green;'>Tables created successfully.</p>";
    } catch (PDOException $e) {
        die("<p style='color:red;'>Table creation error: " . $e->getMessage() . "</p>");
    }


// had to create some default values for individuals and families that did not exist in the GEDCOM file
try {
    $pdo->exec("INSERT IGNORE INTO individuals (id, name, birth_date, death_date) VALUES ('0000', 'Unknown', NULL, NULL)");
    
} catch (PDOException $e) {
    echo "<p style='color:red;'>Error creating default individual: " . $e->getMessage() . "</p>";
}

try {
    $pdo->exec("INSERT IGNORE INTO families (id, husband, wife) VALUES ('0000', '0000', '0000')");
    
} catch (PDOException $e) {
    echo "<p style='color:red;'>Error creating default family: " . $e->getMessage() . "</p>";
}

  // Parse GEDCOM
  $gedcom = file_get_contents($gedcomFile);
  $lines = explode("\n", $gedcom);
  $individuals = [];
  $families = [];
  $currentIndi = null;
  $currentFam = null;
  $subTag = null;

  foreach ($lines as $line) {
      $parts = explode(' ', trim($line), 3);
      if (count($parts) < 2) continue;
      list($level, $tag) = $parts;
      $data = $parts[2] ?? '';

      if ($level == '0' && preg_match('/@(I\\d+)@/', $tag, $matches)) {
          $currentIndi = $matches[1];
          $currentFam = null;
          $individuals[$currentIndi] = ['id' => $currentIndi];
      } elseif ($level == '1' && $currentIndi) {
          if ($tag == 'NAME') {
              $individuals[$currentIndi]['name'] = $data;
          } elseif ($tag == 'BIRT' || $tag == 'DEAT') {
              $subTag = strtolower($tag);
          }
      } elseif ($level == '2' && isset($subTag) && $currentIndi && $tag == 'DATE') {
          $individuals[$currentIndi][$subTag] = $data;
      } elseif ($level == '0' && preg_match('/@(F\\d+)@/', $tag, $matches)) {
          $currentFam = $matches[1];
          $currentIndi = null;
          $families[$currentFam] = ['id' => $currentFam, 'husband' => null, 'wife' => null, 'children' => []];
      } elseif ($level == '1' && $currentFam) {
          if ($tag == 'HUSB') {
              $families[$currentFam]['husband'] = trim($data, '@');
          } elseif ($tag == 'WIFE') {
              $families[$currentFam]['wife'] = trim($data, '@');
          } elseif ($tag == 'CHIL') {
              $families[$currentFam]['children'][] = trim($data, '@');
          }
      }
  }

  // Combine GIVN and SURN if NAME is missing
  foreach ($individuals as &$indi) {
      if (empty($indi['name'])) {
          $indi['name'] = 'Unknown Name'; // Fallback for missing names
      }
  }

  // Insert individuals
  $insertIndividual = $pdo->prepare("INSERT INTO individuals (id, name, birth_date, death_date) VALUES (:id, :name, :birth_date, :death_date)");

  foreach ($individuals as $indi) {


      // Check for duplicate ID in the database
      $checkDuplicate = $pdo->prepare("SELECT COUNT(*) FROM individuals WHERE id = :id");
      $checkDuplicate->execute([':id' => $indi['id']]);
      $count = $checkDuplicate->fetchColumn();

      if ($count > 0) {
          echo "<p style='color:orange;'>Skipping duplicate individual ID: " . htmlspecialchars($indi['id']) . "</p>";
          continue; // Skip this individual
      }

      try {
          $insertIndividual->execute([
              ':id' => $indi['id'],
              ':name' => $indi['name'],
              ':birth_date' => $indi['birt'] ?? null,
              ':death_date' => $indi['deat'] ?? null
          ]);
          
      } catch (PDOException $e) {
          echo "<p style='color:red;'>Error inserting individual: " . $e->getMessage() . "</p>";
        
      }
  }

  // Insert families
  $insertFamily = $pdo->prepare("INSERT INTO families (id, husband, wife) VALUES (:id, :husband, :wife)");

  foreach ($families as $fam) {
    // Check if husband exists
    $checkHusband = $pdo->prepare("SELECT COUNT(*) FROM individuals WHERE id = :id");
    $checkHusband->execute([':id' => $fam['husband']]);
    $husbandToUse = $checkHusband->fetchColumn() > 0 ? $fam['husband'] : '0000';

    // Check if wife exists
    $checkWife = $pdo->prepare("SELECT COUNT(*) FROM individuals WHERE id = :id");
    $checkWife->execute([':id' => $fam['wife']]);
    $wifeToUse = $checkWife->fetchColumn() > 0 ? $fam['wife'] : '0000';


    try {
        $insertFamily->execute([
            ':id' => $fam['id'],
            ':husband' => $husbandToUse,
            ':wife' => $wifeToUse
        ]);
        echo "<p style='color:green;'>Inserted family: " . htmlspecialchars($fam['id']) . "</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red;'>Error inserting family: " . $e->getMessage() . "</p>";
       
    }
}

  // family-children relationships
  $insertFamilyChild = $pdo->prepare("INSERT INTO family_children (family_id, child_id) VALUES (:family_id, :child_id)");

foreach ($families as $fam) {
    foreach ($fam['children'] as $child) {
        // Check if the child exists as an individual
        $checkChild = $pdo->prepare("SELECT COUNT(*) FROM individuals WHERE id = :id");
        $checkChild->execute([':id' => $child]);
        $childToUse = $checkChild->fetchColumn() > 0 ? $child : '0000'; // Use '0000' if child is missing

        // Check if the family exists in the families table. GEDCOMs are stupid.
        $checkFamily = $pdo->prepare("SELECT COUNT(*) FROM families WHERE id = :id");
        $checkFamily->execute([':id' => $fam['id']]);
        $familyToUse = $checkFamily->fetchColumn() > 0 ? $fam['id'] : '0000'; // Use '0000' if family is missing

        echo "<p>Processing child: " . htmlspecialchars($childToUse) . " for family: " . htmlspecialchars($familyToUse) . "</p>";

        try {
            $insertFamilyChild->execute([
                ':family_id' => $familyToUse,
                ':child_id' => $childToUse
            ]);
            echo "<p style='color:green;'>Inserted child: " . htmlspecialchars($childToUse) . " into family: " . htmlspecialchars($familyToUse) . "</p>";
        } catch (PDOException $e) {
            echo "<p style='color:red;'>Error inserting child: " . $e->getMessage() . "</p>";
            
        }
    }
}
    echo "<p style='color:green;'>GEDCOM data imported successfully.</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>GEDCOM Import Installer</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: auto; padding-top: 2em; }
        label { display: block; margin-top: 1em; }
        input[type="text"], input[type="password"], input[type="file"] {
            width: 100%; padding: 0.5em;
        }
        input[type="submit"] {
            margin-top: 2em;
            padding: 0.75em 2em;
        }
    </style>
</head>
<body>
    <h1>GEDCOM Import Installer</h1>
    <form method="post" enctype="multipart/form-data">
        <label>GEDCOM File:</label>
        <input type="file" name="gedcom" required>

        <label>MySQL Host:</label>
        <input type="text" name="host" value="localhost" required>

        <label>Database Name:</label>
        <input type="text" name="dbname" required>

        <label>MySQL Username:</label>
        <input type="text" name="user" required>

        <label>MySQL Password:</label>
        <input type="password" name="pass" required>

        <input type="submit" value="Install and Import">
    </form>
</body>
</html>