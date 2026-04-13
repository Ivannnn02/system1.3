<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

smartenroll_require_role('admin');

function manage_labelize(string $key): string
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

    return ucwords(trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', $key))));
}

function manage_normalize_date(string $value): string
{
    if ($value === '') {
        return '';
    }

    foreach (['Y-m-d', 'm/d/Y', 'd/m/Y'] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('Y-m-d', $timestamp) : '';
}

function manage_school_year(string $completionDate): string
{
    $timestamp = $completionDate !== '' ? strtotime($completionDate) : false;
    if ($timestamp === false) {
        $timestamp = time();
    }

    $month = (int)date('n', $timestamp);
    $year = (int)date('Y', $timestamp);
    $startYear = $month >= 6 ? $year : ($year - 1);

    return $startYear . '-' . ($startYear + 1);
}

function manage_next_student_id(mysqli $conn): string
{
    $prefix = '202600';
    $nextNumber = 1;

    while (true) {
        $candidate = $prefix . $nextNumber;
        $stmt = $conn->prepare("SELECT 1 FROM `enrollments` WHERE `student_id` = ? LIMIT 1");
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            return $candidate;
        }

        $nextNumber++;
    }
}

$sectionMap = [
    'Enrollment Info' => [
        'student_id', 'grade_level', 'completion_date', 'school_year'
    ],
    'Learner Information' => [
        'learner_lname', 'learner_fname', 'learner_mname', 'learner_ext', 'nickname', 'sex', 'dob', 'age',
        'mother_tongue', 'religion', 'email'
    ],
    'Address Information' => [
        'province', 'municipality', 'barangay', 'street'
    ],
    'Father Information' => [
        'father_lname', 'father_fname', 'father_mname', 'father_occ', 'father_contact'
    ],
    'Mother Information' => [
        'mother_lname', 'mother_fname', 'mother_mname', 'mother_occ', 'mother_contact', 'mother_maiden'
    ],
    'Guardian Information' => [
        'guardian_type', 'guardian_lname', 'guardian_fname', 'guardian_mname', 'guardian_occ', 'guardian_contact'
    ],
    'Special Education Needs' => [
        'special_needs', 'medication', 'medication_details'
    ],
    'Emergency Contacts' => [
        'emergency1_name', 'emergency1_contact', 'emergency1_relationship',
        'emergency2_name', 'emergency2_contact', 'emergency2_relationship',
        'emergency3_name', 'emergency3_contact', 'emergency3_relationship'
    ],
];

$columns = [];
$student = [];
$error = '';
$isCreate = !isset($_GET['id']) || (int)($_GET['id'] ?? 0) <= 0;
$pageTitle = $isCreate ? 'Add Enrollment' : 'Edit Student';
$pageDescription = $isCreate ? 'Create a full enrollment record from the admin account.' : 'Update the full enrollment details.';
$submitLabel = $isCreate ? 'Save Enrollment' : 'Save Changes';

try {
    $conn = new mysqli('127.0.0.1', 'root', '', 'smartenroll');
    $conn->set_charset('utf8mb4');

    $colRes = $conn->query("SHOW COLUMNS FROM `enrollments`");
    while ($row = $colRes->fetch_assoc()) {
        $columns[] = $row['Field'];
    }

    if (empty($columns)) {
        throw new RuntimeException('Unable to read enrollments table columns.');
    }

    foreach ($columns as $column) {
        $student[$column] = '';
    }

    $skip = ['id', 'created_at'];
    $readOnly = ['student_id', 'school_year', 'created_at'];

    $today = date('Y-m-d');
    if (array_key_exists('completion_date', $student)) {
        $student['completion_date'] = $today;
    }
    if (array_key_exists('school_year', $student)) {
        $student['school_year'] = manage_school_year($student['completion_date'] ?? $today);
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$isCreate) {
        $stmt = $conn->prepare("SELECT * FROM `enrollments` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result->fetch_assoc();
        $stmt->close();

        if (!$existing) {
            throw new RuntimeException('Student record not found.');
        }

        foreach ($existing as $key => $value) {
            $student[$key] = (string)$value;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = [];

        foreach ($columns as $col) {
            if (in_array($col, $skip, true) || $col === 'school_year') {
                continue;
            }

            if ($col === 'student_id' && $isCreate) {
                continue;
            }

            if (array_key_exists($col, $_POST)) {
                $data[$col] = trim((string)$_POST[$col]);
            }
        }

        if (isset($data['completion_date'])) {
            $data['completion_date'] = manage_normalize_date($data['completion_date']);
        }
        if (isset($data['dob'])) {
            $data['dob'] = manage_normalize_date($data['dob']);
        }
        if (array_key_exists('school_year', $student)) {
            $data['school_year'] = manage_school_year((string)($data['completion_date'] ?? $student['completion_date'] ?? ''));
        }
        if (array_key_exists('learner_ext', $student) && !isset($data['learner_ext'])) {
            $data['learner_ext'] = '';
        }
        if (array_key_exists('medication_details', $student) && !isset($data['medication_details'])) {
            $data['medication_details'] = '';
        }

        if ($isCreate) {
            $data['student_id'] = manage_next_student_id($conn);

            $fields = array_keys($data);
            $fieldSql = '`' . implode('`,`', $fields) . '`';
            $placeholderSql = implode(',', array_fill(0, count($fields), '?'));
            $stmt = $conn->prepare("INSERT INTO `enrollments` ({$fieldSql}) VALUES ({$placeholderSql})");
            $types = str_repeat('s', count($fields));
            $values = array_values($data);
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $stmt->close();

            header('Location: student_list.php?status=created');
            exit;
        }

        $set = [];
        $types = '';
        $values = [];
        foreach ($data as $col => $val) {
            $set[] = "`{$col}` = ?";
            $types .= 's';
            $values[] = $val;
        }
        $types .= 'i';
        $values[] = $id;

        $stmt = $conn->prepare("UPDATE `enrollments` SET " . implode(', ', $set) . " WHERE `id` = ?");
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();

        header('Location: student_list.php?status=updated');
        exit;
    }

    $usedFields = [];
    foreach ($sectionMap as $fields) {
        foreach ($fields as $field) {
            $usedFields[$field] = true;
        }
    }

    $otherFields = [];
    foreach ($columns as $column) {
        if (!isset($usedFields[$column]) && !in_array($column, ['id', 'created_at'], true)) {
            $otherFields[] = $column;
        }
    }
    if (!empty($otherFields)) {
        $sectionMap['Other Details'] = $otherFields;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMARTENROLL | <?php echo htmlspecialchars($pageTitle); ?></title>
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
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p><?php echo htmlspecialchars($pageDescription); ?></p>
            </div>
        </div>
    </div>

    <div class="student-edit-card">
        <?php if ($error): ?>
            <div class="student-error">
                <strong>Unable to load enrollment form.</strong>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php else: ?>
            <form method="post">
                <?php foreach ($sectionMap as $sectionTitle => $fields): ?>
                    <div class="detail-section">
                        <h3 class="detail-section-title"><?php echo htmlspecialchars($sectionTitle); ?></h3>
                        <div class="student-edit-grid">
                            <?php foreach ($fields as $col): ?>
                                <?php if (!in_array($col, $columns, true) || in_array($col, $skip, true)) { continue; } ?>
                                <?php $val = (string)($student[$col] ?? ''); ?>
                                <label class="edit-item">
                                    <span class="detail-label"><?php echo htmlspecialchars(manage_labelize($col)); ?></span>
                                    <?php if ($col === 'learner_ext'): ?>
                                        <select name="learner_ext">
                                            <?php foreach (['' => 'None', 'Jr' => 'Jr.', 'Sr' => 'Sr.', 'II' => 'II', 'III' => 'III'] as $optVal => $optLabel): ?>
                                                <option value="<?php echo htmlspecialchars($optVal); ?>" <?php echo $val === $optVal ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($optLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($col === 'sex'): ?>
                                        <select name="sex">
                                            <?php foreach (['' => 'Select', 'Male' => 'Male', 'Female' => 'Female'] as $optVal => $optLabel): ?>
                                                <option value="<?php echo htmlspecialchars($optVal); ?>" <?php echo $val === $optVal ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($optLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($col === 'guardian_type'): ?>
                                        <select name="guardian_type">
                                            <?php foreach (['' => 'Select', 'other' => 'Other', 'mother' => 'Mother', 'father' => 'Father'] as $optVal => $optLabel): ?>
                                                <option value="<?php echo htmlspecialchars($optVal); ?>" <?php echo $val === $optVal ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($optLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($col === 'medication'): ?>
                                        <select name="medication">
                                            <?php foreach (['' => 'Select', 'yes' => 'Yes', 'no' => 'No'] as $optVal => $optLabel): ?>
                                                <option value="<?php echo htmlspecialchars($optVal); ?>" <?php echo $val === $optVal ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($optLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($col === 'grade_level'): ?>
                                        <select name="grade_level">
                                            <?php foreach (['' => 'Select', 'Toddler' => 'Toddler', 'Casa' => 'Casa', 'Kindergarten' => 'Kindergarten', 'Brave' => 'Brave SpEd', 'Grade 1' => 'Grade 1', 'Grade 2' => 'Grade 2'] as $optVal => $optLabel): ?>
                                                <option value="<?php echo htmlspecialchars($optVal); ?>" <?php echo $val === $optVal ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($optLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($col === 'completion_date' || $col === 'dob'): ?>
                                        <input type="date" name="<?php echo htmlspecialchars($col); ?>" value="<?php echo htmlspecialchars(manage_normalize_date($val)); ?>">
                                    <?php elseif ($col === 'age'): ?>
                                        <input type="number" name="age" value="<?php echo htmlspecialchars($val); ?>" readonly>
                                    <?php elseif ($col === 'email'): ?>
                                        <input type="email" name="email" value="<?php echo htmlspecialchars($val); ?>">
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
                    <button type="submit" class="edit-save"><?php echo htmlspecialchars($submitLabel); ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>

<script src="js/student_edit.js"></script>
</body>
</html>
