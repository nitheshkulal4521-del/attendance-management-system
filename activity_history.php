<?php

session_start();
require 'db_connect.php';

if (!isset($_SESSION['teacher_id'])) {
    header("Location: teacher_login.php");
    exit();
}

$user_id = (int) $_SESSION['teacher_id'];


/* =========================================================
   GET TEACHER
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        teacher_id,
        teacher_name
    FROM teachers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) {
    session_destroy();
    header("Location: teacher_login.php");
    exit();
}

$teacher_id   = (int) $teacher['teacher_id'];
$teacher_name = $teacher['teacher_name'];


/* =========================================================
   GET ALL ACTIVITIES
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        al.activity_id,
        al.action_type,
        al.description,
        al.created_at,

        c.class_name,
        c.semester

    FROM activity_logs al

    LEFT JOIN classes c
        ON c.class_id = al.class_id

    WHERE al.teacher_id = ?

    ORDER BY
        al.created_at DESC,
        al.activity_id DESC
");

$stmt->bind_param("i", $teacher_id);
$stmt->execute();

$result = $stmt->get_result();

$activities = [];

while ($row = $result->fetch_assoc()) {
    $activities[] = $row;
}

$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Activity History – EduTrack ERP</title>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="shared.css"
>

<style>

.activity-description {
    font-size:.75rem;
    color:var(--muted);
    margin-top:4px;
    line-height:1.5;
}

.empty-activity {
    text-align:center;
    padding:40px;
    color:var(--muted);
}

</style>

</head>

<body>


<div
    id="topbar-mount"
    data-page="Activity History"
    data-teacher="<?= htmlspecialchars(
        $teacher_name,
        ENT_QUOTES,
        'UTF-8'
    ) ?>">
</div>


<div
    id="sidebar-mount"
    data-active="dashboard">
</div>


<div class="main">

<div class="page-content">


    <!-- Breadcrumb -->

    <div class="breadcrumb">

        <a href="teacher_dashboard.php">
            Dashboard
        </a>

        <svg viewBox="0 0 24 24">
            <polyline points="9 18 15 12 9 6"/>
        </svg>

        <span>
            Activity History
        </span>

    </div>


    <!-- Header -->

    <div class="page-header">

        <h1 class="page-title">
            Activity History
        </h1>

        <p class="page-sub">
            Recent actions performed from your teacher account
        </p>

    </div>


    <!-- Table -->

    <div class="card" style="padding:0">

        <div
            class="table-wrap"
            style="border:none;border-radius:14px"
        >

            <table>

                <thead>

                    <tr>
                        <th>#</th>
                        <th>Action</th>
                        <th>Class</th>
                        <th>Date & Time</th>
                        <th>Status</th>
                    </tr>

                </thead>


                <tbody>

                <?php if (!empty($activities)): ?>

                    <?php
                    $number = 1;

                    foreach ($activities as $activity):

                        $action =
                            $activity['action_type'];


                        if ($action === 'Attendance Marked') {

                            $badge_class =
                                'badge-green';

                            $status_text =
                                'Saved';

                        } elseif ($action === 'Student Added') {

                            $badge_class =
                                'badge-blue';

                            $status_text =
                                'Success';

                        } elseif ($action === 'Grace Granted') {

                            $badge_class =
                                'badge-green';

                            $status_text =
                                'Granted';

                        } elseif ($action === 'Grace Denied') {

                            $badge_class =
                                'badge-red';

                            $status_text =
                                'Denied';

                        } elseif ($action === 'Class Created') {

                            $badge_class =
                                'badge-blue';

                            $status_text =
                                'Created';

                        } elseif ($action === 'Class Updated') {

                            $badge_class =
                                'badge-yellow';

                            $status_text =
                                'Updated';

                        } else {

                            $badge_class =
                                'badge-blue';

                            $status_text =
                                'Done';
                        }
                    ?>

                    <tr>

                        <td>
                            <?= $number++ ?>
                        </td>


                        <td>

                            <strong>
                                <?= htmlspecialchars(
                                    $activity['action_type']
                                ) ?>
                            </strong>

                            <div class="activity-description">

                                <?= htmlspecialchars(
                                    $activity['description']
                                ) ?>

                            </div>

                        </td>


                        <td>

                            <?php if (!empty($activity['class_name'])): ?>

                                <?= htmlspecialchars(
                                    $activity['class_name']
                                ) ?>

                                <?php if (!empty($activity['semester'])): ?>

                                    <span style="color:var(--muted)">

                                        (Sem
                                        <?= (int)$activity['semester'] ?>
                                        )

                                    </span>

                                <?php endif; ?>


                            <?php else: ?>

                                <span style="color:var(--muted)">
                                    —
                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <?= date(
                                'd M Y, h:i A',
                                strtotime(
                                    $activity['created_at']
                                )
                            ) ?>

                        </td>


                        <td>

                            <span
                                class="badge <?= $badge_class ?>"
                            >

                                <span class="badge-dot"></span>

                                <?= $status_text ?>

                            </span>

                        </td>


                    </tr>

                    <?php endforeach; ?>


                <?php else: ?>

                    <tr>

                        <td
                            colspan="5"
                            class="empty-activity"
                        >
                            No activity recorded yet.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


</div>

</div>


<script src="shared.js"></script>

</body>

</html>