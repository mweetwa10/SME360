<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    $_SESSION['error'] = "Unauthorized access.";
    header("Location: special-dash.php");
    exit;
}

$doctor_id = $_SESSION['user_id'];

$patient_id         = trim($_POST['patient_id']         ?? '');
$consultation_notes = trim($_POST['consultation_notes'] ?? '');
$diagnosis          = trim($_POST['diagnosis']          ?? '');
$prescription       = trim($_POST['prescription']       ?? '');
$consultation_date  = trim($_POST['consultation_date']  ?? '');
$doctor_notes       = trim($_POST['doctor_notes']       ?? '');
$dosage             = trim($_POST['dosage']             ?? '');
$duration           = trim($_POST['duration']           ?? '');
$frequency          = trim($_POST['frequency']          ?? '');
$instructions       = trim($_POST['instructions']       ?? '');

if (
    empty($patient_id) ||
    empty($consultation_notes) ||
    empty($diagnosis) ||
    empty($consultation_date)
) {
    $_SESSION['error'] = "Please fill in all required fields.";
    header("Location: special-dash.php");
    exit;
}

// ── 1. Save consultation ───────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO consultations
        (patient_id, doctor_id, consultation_notes, diagnosis,
         prescription, doctor_notes, consultation_date)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    $_SESSION['error'] = "Database error: " . $conn->error;
    header("Location: special-dash.php");
    exit;
}

$stmt->bind_param(
    "sssssss",
    $patient_id,
    $doctor_id,
    $consultation_notes,
    $diagnosis,
    $prescription,
    $doctor_notes,
    $consultation_date
);

if (!$stmt->execute()) {
    $_SESSION['error'] = "Failed to save consultation: " . $stmt->error;
    header("Location: special-dash.php");
    exit;
}

$consultation_number = $stmt->insert_id;
$stmt->close();

$consultation_id = "CON" . str_pad($consultation_number, 4, "0", STR_PAD_LEFT);

// ── 2. Save prescription if medicine selected ──────────────────────────
if (!empty($prescription)) {

    $medStmt = $conn->prepare("
        SELECT medicine_id FROM medicines WHERE name = ? LIMIT 1
    ");
    $medStmt->bind_param("s", $prescription);
    $medStmt->execute();
    $medResult = $medStmt->get_result();

    if ($medResult->num_rows > 0) {
        $medicine_id = $medResult->fetch_assoc()['medicine_id'];

        $prescriptionStmt = $conn->prepare("
            INSERT INTO prescriptions
                (patient_id, doctor_id, diagnosis, consultation_notes,
                 medicine_id, dosage, duration, frequency, instructions,
                 status, quantity_dispensed)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $status             = "Pending";
        $quantity_dispensed = 0;

        $prescriptionStmt->bind_param(
            "ssssisssssi",
            $patient_id,
            $doctor_id,
            $diagnosis,
            $consultation_notes,
            $medicine_id,
            $dosage,
            $duration,
            $frequency,
            $instructions,
            $status,
            $quantity_dispensed
        );

        if (!$prescriptionStmt->execute()) {
            $_SESSION['error'] = "Failed to create prescription: " . $prescriptionStmt->error;
            header("Location: special-dash.php");
            exit;
        }

        $prescriptionStmt->close();
    }

    $medStmt->close();
}

// ── 3. Mark referral as completed ─────────────────────────────────────
// Targets the most recent non-completed referral for this patient/specialist
$refStmt = $conn->prepare("
    UPDATE preferrals
    SET    status = 'completed'
    WHERE  patient_id    = ?
      AND  specialist_id = ?
      AND  status       != 'completed'
    ORDER BY referred_at DESC
    LIMIT 1
");
$refStmt->bind_param("ss", $patient_id, $doctor_id);
$refStmt->execute();
$refStmt->close();

// ── 4. Mark doctor queue as completed (if patient was also in queue) ───
$queueStmt = $conn->prepare("
    UPDATE doctor_queue
    SET    status = 'Completed'
    WHERE  doctor_id  = ?
      AND  patient_id = ?
      AND  status     = 'Waiting'
");
$queueStmt->bind_param("ss", $doctor_id, $patient_id);
$queueStmt->execute();
$queueStmt->close();

$_SESSION['success'] = "Consultation saved successfully. ID: " . $consultation_id;
header("Location: special-dash.php");
exit;
?>