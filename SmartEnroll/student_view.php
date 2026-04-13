<?php
require_once __DIR__ . '/auth.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

smartenroll_require_login();

$student = null;
$columns = [];
$error = '';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMARTENROLL | Student Details</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/student_view.css">
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
                <h1>Student Details</h1>
                <p>Full enrollment information.</p>
            </div>
        </div>
    </div>

    <div class="student-detail-card">
        <?php if ($error): ?>
            <div class="student-error">
                <strong>Unable to load student.</strong>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php else: ?>
            <?php
                $fullName = trim(
                    ($student['learner_lname'] ?? '') . ', ' .
                    ($student['learner_fname'] ?? '') . ' ' .
                    ($student['learner_mname'] ?? '')
                );
                $fullName = trim(preg_replace('/\s+/', ' ', $fullName), " ,");
                $studentId = $student['student_id'] ?? '';
                $gradeLevel = $student['grade_level'] ?? '';
            ?>
            <div class="student-summary">
                <div>
                    <span class="summary-label">Student Name</span>
                    <h2 class="summary-name"><?php echo htmlspecialchars($fullName !== '' ? $fullName : 'Student'); ?></h2>
                </div>
                <div class="summary-meta">
                    <div>
                        <span class="summary-label">Student ID</span>
                        <span class="summary-value"><?php echo htmlspecialchars($studentId !== '' ? $studentId : '—'); ?></span>
                    </div>
                    <div>
                        <span class="summary-label">Grade Level</span>
                        <span class="summary-value"><?php echo htmlspecialchars($gradeLevel !== '' ? $gradeLevel : '—'); ?></span>
                    </div>
                </div>
            </div>
            <?php
                $sectionMap = [
                    'Enrollment Info' => [
                        'student_id','grade_level','completion_date','school_year','created_at'
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

                $skipCols = ['id'];
            ?>

            <?php foreach ($sectionMap as $sectionTitle => $fields): ?>
                <div class="detail-section">
                    <h3 class="detail-section-title"><?php echo htmlspecialchars($sectionTitle); ?></h3>
                    <div class="student-detail-grid">
                        <?php foreach ($fields as $col): ?>
                            <?php if (in_array($col, $skipCols, true)) { continue; } ?>
                            <div class="detail-item">
                                <span class="detail-label"><?php echo htmlspecialchars(labelize($col)); ?></span>
                                <?php $val = trim((string)($student[$col] ?? '')); ?>
                                <span class="detail-value"><?php echo htmlspecialchars($val !== '' ? $val : '—'); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

</body>
</html>
