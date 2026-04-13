<?php
require_once __DIR__ . '/auth.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

smartenroll_require_role('admin');

$student = null;
$columns = [];
$error = '';
$success = '';
$showPopup = isset($_GET['saved']) && $_GET['saved'] === '1';

try {
    $conn = new mysqli('127.0.0.1', 'root', '', 'smartenroll');
    $conn->set_charset('utf8mb4');

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        throw new RuntimeException('Invalid student ID.');
    }

    $colRes = $conn->query("SHOW COLUMNS FROM `enrollments`");
    while ($row = $colRes->fetch_assoc()) {
        $columns[] = $row['Field'];
    }

    $skip = ['id'];
    $readOnly = ['student_id', 'created_at'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = [];
        foreach ($columns as $col) {
            if (in_array($col, $skip, true)) {
                continue;
            }
            if (isset($_POST[$col])) {
                $data[$col] = trim((string)$_POST[$col]);
            }
        }

        if (!empty($data)) {
            $set = [];
            $types = '';
            $values = [];
            foreach ($data as $col => $val) {
                $set[] = "`$col` = ?";
                $types .= 's';
                $values[] = $val;
            }
            $types .= 'i';
            $values[] = $id;

            $sql = "UPDATE `enrollments` SET " . implode(', ', $set) . " WHERE `id` = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $stmt->close();
            $success = 'Student record updated.';
            header('Location: student_edit.php?id=' . $id . '&saved=1');
            exit;
        }
    }

    $stmt = $conn->prepare("SELECT * FROM `enrollments` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();

    if (!$student) {
        throw new RuntimeException('Student record not found.');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function labelize(string $key): string
{
    $map = [
        'learner_lname' => 'Learner Last Name',
        'learner_fname' => 'Learner First Name',
        'learner_mname' => 'Learner Middle Name',
        'learner_ext' => 'Learner Extension Name',
        'mother_maiden' => 'Mother Maiden Full Name',
        'father_occ' => 'Father Occupation',
        'mother_occ' => 'Mother Occupation',
        'guardian_occ' => 'Guardian Occupation',
        'guardian_contact' => 'Guardian Contact Number',
        'father_contact' => 'Father Contact Number',
        'mother_contact' => 'Mother Contact Number',
        'emergency1_name' => 'Emergency 1 Name',
        'emergency1_contact' => 'Emergency 1 Contact',
        'emergency1_relationship' => 'Emergency 1 Relationship',
        'emergency2_name' => 'Emergency 2 Name',
        'emergency2_contact' => 'Emergency 2 Contact',
        'emergency2_relationship' => 'Emergency 2 Relationship',
        'emergency3_name' => 'Emergency 3 Name',
        'emergency3_contact' => 'Emergency 3 Contact',
        'emergency3_relationship' => 'Emergency 3 Relationship',
        'dob' => 'Date of Birth',
    ];

    if (isset($map[$key])) {
        return $map[$key];
    }

    $key = str_replace('_', ' ', $key);
    $key = preg_replace('/\s+/', ' ', $key);
    return ucwords(trim($key));
}

$sectionMap = [
    'Enrollment Info' => [
        'student_id','grade_level','completion_date'
    ],
    'Learner Information' => [
        'learner_lname','learner_fname','learner_mname','learner_ext','nickname','sex','dob','age',
        'mother_tongue','religion','email'
    ],
    'Address Information' => [
        'province','municipality','barangay','street'
    ],
    'Father Information' => [
        'father_lname','father_fname','father_mname','father_occ','father_contact'
    ],
    'Mother Information' => [
        'mother_lname','mother_fname','mother_mname','mother_occ','mother_contact','mother_maiden'
    ],
    'Guardian Information' => [
        'guardian_type','guardian_lname','guardian_fname','guardian_mname','guardian_occ','guardian_contact'
    ],
    'Special Education Needs' => [
        'special_needs','medication','medication_details'
    ],
    'Emergency Contacts' => [
        'emergency1_name','emergency1_contact','emergency1_relationship',
        'emergency2_name','emergency2_contact','emergency2_relationship',
        'emergency3_name','emergency3_contact','emergency3_relationship'
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMARTENROLL | Edit Student</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/student_edit.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="dashboard-page dashboard-white-page">

<main class="dashboard-main">
    <div class="dashboard-header student-header">
        <div class="student-header-left">
            <a href="student_list.php" class="dashboard-link back-left">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="student-header-title">
                <h1>Edit Student</h1>
                <p>Update enrollment details.</p>
            </div>
        </div>
    </div>

    <div class="student-edit-card">
        <?php if ($error): ?>
            <div class="student-error">
                <strong>Unable to load student.</strong>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php else: ?>
            <?php if ($showPopup): ?>
                <div id="successPopup" class="popup-overlay">
                    <div class="popup-box">
                        <!-- LOGO → CHECK MORPH ICON -->
                        <div class="popup-icon success-icon" id="successIcon">
                            <img src="assets/logo.png" id="successLogo" alt="Logo">
                            <i class="fas fa-check" id="successCheck"></i>
                        </div>
                       
                        <h2>Changes Saved!</h2>
                        <p>The student record was updated successfully.</p>
                        <button class="popup-btn" id="closeSuccess">OK</button>
                    </div>
                </div>
            <?php endif; ?>
            <form method="post">
                <?php foreach ($sectionMap as $sectionTitle => $fields): ?>
                    <div class="detail-section">
                        <h3 class="detail-section-title"><?php echo htmlspecialchars($sectionTitle); ?></h3>
                        <div class="student-edit-grid">
                            <?php foreach ($fields as $col): ?>
                                <?php if (in_array($col, $skip, true)) { continue; } ?>
                                <label class="edit-item">
                                    <span class="detail-label"><?php echo htmlspecialchars(labelize($col)); ?></span>
                                    <?php $val = (string)($student[$col] ?? ''); ?>
                                    <?php if ($col === 'learner_ext'): ?>
                                        <select name="learner_ext" <?php echo in_array($col, $readOnly, true) ? 'disabled' : ''; ?>>
                                            <?php
                                                $extOptions = ['' => 'None', 'Jr' => 'Jr.', 'Sr' => 'Sr.', 'II' => 'II', 'III' => 'III'];
                                                foreach ($extOptions as $optVal => $optLabel):
                                                    $selected = ($val === $optVal) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo htmlspecialchars($optVal); ?>" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($optLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($col === 'sex'): ?>
                                        <select name="sex">
                                            <?php
                                                $sexOptions = ['' => 'Select', 'Male' => 'Male', 'Female' => 'Female'];
                                                foreach ($sexOptions as $optVal => $optLabel):
                                                    $selected = ($val === $optVal) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo htmlspecialchars($optVal); ?>" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($optLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($col === 'guardian_type'): ?>
                                        <select name="guardian_type">
                                            <?php
                                                $gOptions = ['' => 'Select', 'other' => 'Other', 'mother' => 'Mother', 'father' => 'Father'];
                                                foreach ($gOptions as $optVal => $optLabel):
                                                    $selected = ($val === $optVal) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo htmlspecialchars($optVal); ?>" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($optLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif (in_array($col, ['guardian_lname','guardian_fname','guardian_mname','guardian_occ','guardian_contact'], true)): ?>
                                        <input
                                            type="text"
                                            name="<?php echo htmlspecialchars($col); ?>"
                                            value="<?php echo htmlspecialchars($val); ?>"
                                            data-guardian-field="<?php echo htmlspecialchars($col); ?>"
                                        >
                                    <?php elseif ($col === 'medication'): ?>
                                        <select name="medication">
                                            <?php
                                                $mOptions = ['' => 'Select', 'yes' => 'Yes', 'no' => 'No'];
                                                foreach ($mOptions as $optVal => $optLabel):
                                                    $selected = ($val === $optVal) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo htmlspecialchars($optVal); ?>" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($optLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($col === 'dob'): ?>
                                        <?php
                                            $dobValue = $val;
                                            $dobNormalized = '';
                                            if ($dobValue !== '') {
                                                $date = DateTime::createFromFormat('Y-m-d', $dobValue);
                                                if (!$date) {
                                                    $date = DateTime::createFromFormat('m/d/Y', $dobValue);
                                                }
                                                if (!$date) {
                                                    $date = DateTime::createFromFormat('d/m/Y', $dobValue);
                                                }
                                                if ($date) {
                                                    $dobNormalized = $date->format('Y-m-d');
                                                }
                                            }
                                        ?>
                                        <input
                                            type="date"
                                            name="dob"
                                            value="<?php echo htmlspecialchars($dobNormalized); ?>"
                                        >
                                    <?php elseif ($col === 'age'): ?>
                                        <input
                                            type="number"
                                            name="age"
                                            value="<?php echo htmlspecialchars($val); ?>"
                                            readonly
                                        >
                                    <?php else: ?>
                                        <input
                                            type="text"
                                            name="<?php echo htmlspecialchars($col); ?>"
                                            value="<?php echo htmlspecialchars($val); ?>"
                                            <?php echo in_array($col, $readOnly, true) ? 'readonly' : ''; ?>
                                        >
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="edit-actions">
                    <button type="submit" class="edit-save">Save Changes</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>

<script src="js/student_edit.js"></script>
</body>
</html>
