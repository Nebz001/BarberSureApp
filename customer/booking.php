<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Redirect unauthenticated users to login with return to this page (preserve selected shop)
if (!is_logged_in()) {
  $qs = '';
  if (isset($_GET['shop'])) {
    $qs = '?shop=' . (int)$_GET['shop'];
  }
  redirect('../login.php?next=' . urlencode('customer/booking.php' . $qs));
}
if (!has_role('customer')) redirect('../login.php');

$user = current_user();
$userId = (int)($user['user_id'] ?? 0);

// Check phone completion using database to avoid stale session; no redirect, show toast instead
$needsPhone = false;
try {
  $phoneVal = $user['phone'] ?? null;
  if ($userId) {
    $ph = $pdo->prepare('SELECT phone FROM Users WHERE user_id=?');
    $ph->execute([$userId]);
    $dbPhone = $ph->fetchColumn();
    if ($dbPhone !== false) $phoneVal = $dbPhone;
  }
  if (empty(trim((string)$phoneVal))) {
    $needsPhone = true;
  }
} catch (Throwable $e) {
  if (empty($user['phone'])) $needsPhone = true;
}

// Fetch approved shops (limit for performance, can add search later)
$shops = $pdo->query("SELECT shop_id, shop_name, city FROM Barbershops WHERE status='approved' AND EXISTS (SELECT 1 FROM Shop_Subscriptions s WHERE s.shop_id=Barbershops.shop_id AND s.payment_status='paid' AND CURDATE() BETWEEN s.valid_from AND s.valid_to) ORDER BY shop_name ASC LIMIT 300")
  ->fetchAll(PDO::FETCH_ASSOC) ?: [];

// If a shop is preselected (via query param)
$selectedShopId = isset($_GET['shop']) ? (int)$_GET['shop'] : 0;
if ($selectedShopId && !in_array($selectedShopId, array_column($shops, 'shop_id'))) {
  $selectedShopId = 0; // invalid
}

// Build consistent pre-chat channel (same as shop details modal) for continuity before booking confirmation
$sessionId = session_id();
$preChannel = null;
if ($selectedShopId) {
  $preChannel = 'pre_' . (int)$selectedShopId . '_' . substr(hash('sha256', $sessionId . '|' . (int)$selectedShopId), 0, 20);
}
// Booking page will use the pre-channel directly (so user sees exact same messages) until an appointment is created.
// Fallback to legacy bk_ channel only if no shop selected (generic state)
$bkFallback = 'bk_' . substr(hash('sha256', $sessionId . '|s0'), 0, 24);
$bookingPageChannel = $preChannel ?: $bkFallback;
// Track chanShop only for merge key uniqueness (generic pre_all merge)
$chanShop = $selectedShopId ? ('s' . (int)$selectedShopId) : 's0';

// If user came from generic Find Shops pre-chat (pre_all_<hash>) merge those messages once into this active booking page channel
try {
  if (!isset($_SESSION['merged_pre_all'])) $_SESSION['merged_pre_all'] = [];
  $mergeKey = $bookingPageChannel . '|' . $chanShop;
  if (!in_array($mergeKey, $_SESSION['merged_pre_all'], true)) {
    $didMerge = false; // track if any messages actually merged so we don't lock out future attempts prematurely
    $chatDir = realpath(__DIR__ . '/../storage/chat');
    if ($chatDir) {
      // Generic channel generation mirrors earlier implementation: pre_all_<hash>
      $genericChannel = 'pre_all_' . substr(hash('sha256', $sessionId . '|all-shops'), 0, 20);
      $genericFile = $chatDir . DIRECTORY_SEPARATOR . $genericChannel . '.json';
      if (is_file($genericFile)) {
        $raw = @file_get_contents($genericFile);
        $msgs = [];
        if ($raw) {
          $dec = json_decode($raw, true);
          if (is_array($dec)) $msgs = $dec;
        }
        if ($msgs) {
          $bookingFile = $chatDir . DIRECTORY_SEPARATOR . $bookingPageChannel . '.json';
          $existing = [];
          if (is_file($bookingFile)) {
            $exRaw = @file_get_contents($bookingFile);
            $exDec = json_decode($exRaw, true);
            if (is_array($exDec)) $existing = $exDec;
          }
          $haveIds = array_column($existing, 'id');
          $changed = false;
          foreach ($msgs as $m) {
            if (!isset($m['id'])) continue;
            if (in_array($m['id'], $haveIds, true)) continue;
            $existing[] = $m;
            $changed = true;
          }
          if ($changed) {
            if (count($existing) > 60) $existing = array_slice($existing, -60);
            @file_put_contents($bookingFile, json_encode($existing));
            $didMerge = true;
          }
        }
      }
    }
    // Only record as merged if something was actually merged. This allows a retry if user opened booking page before starting pre-chat.
    if ($didMerge) {
      $_SESSION['merged_pre_all'][] = $mergeKey;
    }
  }
} catch (Throwable $e) {
  // Silent: merging is best-effort.
}

// Fetch services for selected shop
$services = [];
$shopHours = null; // will hold open_time/close_time for selected shop if available
if ($selectedShopId) {
  $svcStmt = $pdo->prepare("SELECT service_id, service_name, duration_minutes, price FROM Services WHERE shop_id=? ORDER BY service_name ASC");
  $svcStmt->execute([$selectedShopId]);
  $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Detect hours columns and fetch if present
  try {
    $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='Barbershops' AND COLUMN_NAME IN ('open_time','close_time')");
    $colStmt->execute();
    $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $hasOpen = in_array('open_time', $cols, true);
    $hasClose = in_array('close_time', $cols, true);
    if ($hasOpen || $hasClose) {
      $sel = 'shop_id';
      if ($hasOpen) $sel .= ', open_time';
      if ($hasClose) $sel .= ', close_time';
      $hStmt = $pdo->prepare("SELECT $sel FROM Barbershops WHERE shop_id = ? AND status='approved' AND EXISTS (SELECT 1 FROM Shop_Subscriptions s WHERE s.shop_id=Barbershops.shop_id AND s.payment_status='paid' AND CURDATE() BETWEEN s.valid_from AND s.valid_to)");
      $hStmt->execute([$selectedShopId]);
      $shopHours = $hStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
  } catch (Throwable $e) {
    // Silently ignore if INFORMATION_SCHEMA not accessible or other issues
  }
}

$errors = [];
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $errors[] = 'Invalid session token.';
  } else {
    if ($needsPhone) {
      $errors[] = 'Please complete your profile with a phone number before booking.';
    }
    $shopId = (int)($_POST['shop_id'] ?? 0);
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $dtRaw = trim($_POST['appointment_date'] ?? '');
    $payment = $_POST['payment_option'] ?? 'cash';
    $notes = trim($_POST['notes'] ?? '');

    // Basic validation
    if (!$shopId) $errors[] = 'Please select a barbershop.';
    if (!$serviceId) $errors[] = 'Select a service.';
    if ($payment !== 'cash' && $payment !== 'online') $errors[] = 'Invalid payment option.';
    $dt = null;
    if ($dtRaw === '') {
      $errors[] = 'Choose a date & time.';
    } else {
      $dt = strtotime($dtRaw);
      if ($dt === false) {
        $errors[] = 'Invalid date/time format.';
      } elseif ($dt < time()) {
        $errors[] = 'Selected time has already passed.';
      }
    }

    // Ensure shop & service relation
    if (!$errors) {
      $chk = $pdo->prepare("SELECT COUNT(*) FROM Services WHERE service_id=? AND shop_id=?");
      $chk->execute([$serviceId, $shopId]);
      if (!(int)$chk->fetchColumn()) {
        $errors[] = 'Service not found for the selected shop.';
      }
    }

    // Anti-spam booking rules
    if (!$errors && $userId && $dt) {
      try {
        // 1) Max 3 bookings per calendar day (for the day of the selected appointment)
        $day = date('Y-m-d', $dt);
        $daily = $pdo->prepare(
          "SELECT COUNT(*) FROM Appointments
                     WHERE customer_id = ?
                       AND DATE(appointment_date) = ?
                       AND status IN ('pending','confirmed')"
        );
        $daily->execute([$userId, $day]);
        if ((int)$daily->fetchColumn() >= 3) {
          $errors[] = 'Daily limit reached: You can only book up to 3 appointments on the same day.';
        }

        // 2) Enforce a 2-hour interval between this and any of your other active appointments
        if (!$errors) {
          $start = date('Y-m-d H:i:s', $dt - 7200); // 2 hours before
          $end   = date('Y-m-d H:i:s', $dt + 7200); // 2 hours after
          $near = $pdo->prepare(
            "SELECT COUNT(*) FROM Appointments
                         WHERE customer_id = ?
                           AND status IN ('pending','confirmed')
                           AND appointment_date BETWEEN ? AND ?"
          );
          $near->execute([$userId, $start, $end]);
          if ((int)$near->fetchColumn() > 0) {
            $errors[] = 'Please allow at least 2 hours between your appointments.';
          }
        }
      } catch (Throwable $e) {
        // If the checks fail for any reason, do not block the user, but log in future if needed
      }
    }

    // Enforce that selected time is within shop opening and closing hours (if defined)
    if (!$errors && $shopId && $dt) {
      try {
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='Barbershops' AND COLUMN_NAME IN ('open_time','close_time')");
        $colStmt->execute();
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('open_time', $cols, true) && in_array('close_time', $cols, true)) {
          $h = $pdo->prepare("SELECT open_time, close_time FROM Barbershops WHERE shop_id=?");
          $h->execute([$shopId]);
          if ($row = $h->fetch(PDO::FETCH_ASSOC)) {
            $o = $row['open_time'] ?? null;
            $c = $row['close_time'] ?? null;
            if ($o && $c) {
              $day = date('Y-m-d', $dt);
              $oTs = strtotime($day . ' ' . (preg_match('/^\d{2}:\d{2}$/', $o) ? $o . ':00' : $o));
              $cTs = strtotime($day . ' ' . (preg_match('/^\d{2}:\d{2}$/', $c) ? $c . ':00' : $c));
              if ($oTs !== false && $cTs !== false) {
                if ($dt < $oTs || $dt >= $cTs) {
                  $errors[] = 'Selected time is outside shop hours.';
                }
              }
            }
          }
        }
      } catch (Throwable $e) {
        // Ignore if schema lookup not permitted
      }
    }

    if (!$errors && $userId) {
      $ins = $pdo->prepare("INSERT INTO Appointments (customer_id, shop_id, service_id, appointment_date, payment_option, notes) VALUES (?,?,?,?,?,?)");
      $ok = $ins->execute([$userId, $shopId, $serviceId, date('Y-m-d H:i:s', $dt), $payment, $notes ?: null]);
      if ($ok) {
        $appointmentId = (int)$pdo->lastInsertId();
        $success = $payment === 'online'
          ? 'Appointment created. Provide your card details below to proceed (demo only; not processed).'
          : 'Appointment booked successfully!';
        // Attempt to migrate current conversation into:
        // 1) A stable booking channel (legacy bk_<session+shop>) for continuity after we stop using pre_ on this page
        // 2) The appointment-specific channel used in booking history (bk_<appointmentId>_<hash>)
        try {
          $chatDir = realpath(__DIR__ . '/../storage/chat');
          if ($chatDir) {
            $sessionId = session_id();
            // We already used $preChannel (if shop selected) as the on-page channel; derive legacy bk channel to persist post-booking
            $legacyBkChannel = 'bk_' . substr(hash('sha256', $sessionId . '|s' . (int)$shopId), 0, 24);
            $preFile = $preChannel ? $chatDir . DIRECTORY_SEPARATOR . $preChannel . '.json' : null;
            $bookingFile = $chatDir . DIRECTORY_SEPARATOR . $legacyBkChannel . '.json';
            // Appointment-specific channel
            $apptChannel = 'bk_' . $appointmentId . '_' . substr(hash('sha256', $sessionId . '|appt|' . $appointmentId), 0, 16);
            $apptFile = $chatDir . DIRECTORY_SEPARATOR . $apptChannel . '.json';

            // Helper to safely decode a chat file
            $readChat = function ($file) {
              if (!is_file($file)) return [];
              $raw = @file_get_contents($file);
              if (!$raw) return [];
              $d = json_decode($raw, true);
              return is_array($d) ? $d : [];
            };
            // Collect sources: any pre messages + any existing legacy booking channel
            $preMsgs = $preFile ? $readChat($preFile) : [];
            $bookMsgs = $readChat($bookingFile);
            $allSource = [];
            foreach ([$preMsgs, $bookMsgs] as $arr) {
              if ($arr) $allSource = array_merge($allSource, $arr);
            }
            if ($allSource) {
              // Deduplicate by id preserving order (older first)
              $dedup = [];
              $seen = [];
              foreach ($allSource as $m) {
                if (!is_array($m) || !isset($m['id'])) continue;
                if (isset($seen[$m['id']])) continue;
                $seen[$m['id']] = true;
                $dedup[] = $m;
              }
              if (count($dedup) > 60) $dedup = array_slice($dedup, -60);
              // If appointment file exists, merge (avoid duplicates)
              $existingAppt = $readChat($apptFile);
              if ($existingAppt) {
                $have = array_column($existingAppt, 'id');
                foreach ($dedup as $m) {
                  if (!in_array($m['id'], $have, true)) $existingAppt[] = $m;
                }
                if (count($existingAppt) > 80) $existingAppt = array_slice($existingAppt, -80);
                @file_put_contents($apptFile, json_encode($existingAppt));
              } else {
                @file_put_contents($apptFile, json_encode($dedup));
              }
            }

            if ($preFile && is_file($preFile)) {
              $raw = @file_get_contents($preFile);
              $preMsgs = [];
              if ($raw) {
                $dec = json_decode($raw, true);
                if (is_array($dec)) $preMsgs = $dec;
              }
              if ($preMsgs) {
                $merged = [];
                if (is_file($bookingFile)) {
                  $exist = json_decode(@file_get_contents($bookingFile), true);
                  if (is_array($exist)) $merged = $exist;
                }
                // Avoid duplicating by message id
                $haveIds = array_column($merged, 'id');
                foreach ($preMsgs as $m) {
                  if (!isset($m['id'])) continue;
                  if (in_array($m['id'], $haveIds, true)) continue;
                  $merged[] = $m;
                }
                // Trim to last 60 to stay small
                if (count($merged) > 60) $merged = array_slice($merged, -60);
                @file_put_contents($bookingFile, json_encode($merged));
                // Add synthetic system message marking booking creation in both booking + appt channels
                $systemMsg = [
                  'id' => bin2hex(random_bytes(5)),
                  'ts' => time(),
                  'role' => 'customer', // keep rendering minimal; could also introduce 'system'
                  'name' => 'System',
                  'msg' => 'Booking confirmed — conversation linked to appointment #' . $appointmentId
                ];
                // Append system note to booking channel
                $merged[] = $systemMsg;
                @file_put_contents($bookingFile, json_encode(count($merged) > 60 ? array_slice($merged, -60) : $merged));
                // Append system note to appt channel too
                $apptExisting = [];
                if (is_file($apptFile)) {
                  $apptExisting = json_decode(@file_get_contents($apptFile), true);
                  if (!is_array($apptExisting)) $apptExisting = [];
                }
                $apptExisting[] = $systemMsg;
                if (count($apptExisting) > 80) $apptExisting = array_slice($apptExisting, -80);
                @file_put_contents($apptFile, json_encode($apptExisting));
              }
            }
          }
        } catch (Throwable $e) {
          // Silently ignore migration failures (ephemeral feature shouldn't block booking)
        }

        // Refresh services for selected
        $selectedShopId = $shopId;
        $svcStmt = $pdo->prepare("SELECT service_id, service_name, duration_minutes, price FROM Services WHERE shop_id=? ORDER BY service_name ASC");
        $svcStmt->execute([$selectedShopId]);
        $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } else {
        $errors[] = 'Failed to create appointment.';
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Book Appointment • BarberSure</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/customer.css" />
  <link rel="stylesheet" href="../assets/css/toast.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <style>
    .layout {
      display: flex;
      flex-direction: column;
      gap: 1.4rem;
    }

    .section {
      background: var(--c-bg-alt);
      border: 1px solid var(--c-border-soft);
      border-radius: var(--radius);
      padding: 1.1rem 1.25rem 1.25rem;
      box-shadow: var(--shadow-elev);
    }

    .section h2 {
      font-size: 1.05rem;
      margin: 0 0 .9rem;
      font-weight: 600;
      letter-spacing: .4px;
      color: var(--c-text-soft);
    }

    form.booking-form {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .field-grid {
      display: grid;
      gap: .9rem 1rem;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      align-items: start;
    }

    /* Prevent intrinsic min-content overflow in grid items */
    .field-grid>div {
      min-width: 0;
    }

    label.field-label {
      font-size: .62rem;
      letter-spacing: .6px;
      font-weight: 600;
      text-transform: uppercase;
      color: var(--c-text-soft);
      display: block;
      margin-bottom: .35rem;
    }

    .control {
      background: var(--c-surface);
      border: 1px solid var(--c-border);
      color: var(--c-text);
      padding: .7rem .75rem;
      font-size: .8rem;
      border-radius: var(--radius-sm);
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
    }

    .control:focus {
      outline: none;
      border-color: var(--c-accent-alt);
      box-shadow: 0 0 0 .12rem rgba(14, 165, 233, .25);
    }

    .inline {
      display: flex;
      align-items: center;
      gap: .6rem;
      flex-wrap: wrap;
    }

    .notes-box {
      min-height: 90px;
      resize: vertical;
      line-height: 1.4;
    }

    .hours-hint {
      margin-top: .35rem;
      font-size: .78rem;
      color: var(--c-text-soft);
      display: flex;
      align-items: center;
      gap: .35rem;
    }

    .muted {
      color: var(--c-text-soft);
      font-size: .68rem;
    }

    /* legacy .alert styles removed (using toast system now) */

    .services-list {
      display: grid;
      gap: .65rem;
      grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
      margin-top: .5rem;
    }

    .service-item {
      background: var(--c-surface);
      border: 1px solid var(--c-border);
      padding: .7rem .75rem;
      border-radius: var(--radius-sm);
      font-size: .74rem;
      display: flex;
      flex-direction: column;
      gap: .4rem;
      cursor: pointer;
      position: relative;
    }

    .service-item:hover {
      border-color: var(--c-accent-alt);
    }

    .service-item.active {
      border-color: var(--c-accent-alt);
      background: linear-gradient(135deg, #1e2732, #1b2530);
    }

    .service-radio {
      position: absolute;
      inset: 0;
      opacity: 0;
      cursor: pointer;
    }

    .badge-pill {
      background: var(--grad-accent);
      color: #fff;
      font-size: .6rem;
      padding: .3rem .65rem;
      border-radius: 40px;
      font-weight: 600;
      letter-spacing: .55px;
    }



    .actions {
      display: flex;
      gap: .7rem;
      flex-wrap: wrap;
      margin-top: .55rem;
    }

    .divider {
      border: 0;
      border-top: 1px solid var(--c-border);
      margin: 1.2rem 0;
    }

    .loading-msg {
      font-size: .68rem;
      color: var(--c-text-soft);
    }

    .layout {
      max-width: 1260px;
      /* allow space for summary */
      margin: 0 auto;
      width: 100%;
      display: flex;
      flex-direction: column;
      gap: 1.4rem;
    }

    .page-container {
      max-width: 1260px;
      margin: 0 auto;
      padding: 0 1rem 2rem;
      width: 100%;
    }


    @media (min-width: 1100px) {
      .layout.two-col {
        flex-direction: row;
        align-items: flex-start;
      }

      .primary-col,
      .summary-col {
        flex: 1 1 50%;
      }

      .primary-col.section {
        padding: 1.3rem 1.4rem 1.55rem;
      }
    }

    .booking-form {
      width: 100%;
    }

    .primary-col {
      width: 100%;
    }

    .summary-col {
      background: var(--c-bg-alt);
      border: 1px solid var(--c-border-soft);
      border-radius: var(--radius);
      padding: 1.3rem 1.4rem 1.55rem;
      box-shadow: var(--shadow-elev);
      position: relative;
      margin-top: 0;
    }

    .live-summary-title {
      font-size: 1.05rem;
      margin: 0 0 .9rem;
      font-weight: 600;
      letter-spacing: .4px;
      color: var(--c-text-soft);
    }

    .live-summary {
      display: grid;
      grid-template-columns: 110px 1fr;
      gap: .55rem .85rem;
      font-size: .8rem;
      color: var(--c-text-soft);
      margin-bottom: 1rem;
    }

    .live-summary strong {
      color: var(--c-text);
      font-weight: 600;
    }

    .summary-hint {
      font-size: .65rem;
      color: var(--c-text-soft);
      line-height: 1.4;
    }

    .sticky-note {
      position: sticky;
      top: 82px;
    }

    /* Larger, more readable form fields */
    .control {
      font-size: 1rem;
      padding: 0.75rem;
      min-height: 48px;
    }

    .field-label {
      font-size: 1.1rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .service-item {
      padding: 1rem;
      font-size: 1rem;
    }

    .notes-box {
      min-height: 80px;
      font-size: 1rem;
    }

    /* On narrower screens, force single column to avoid overlap of complex inputs */
    @media (max-width: 640px) {
      .field-grid {
        grid-template-columns: 1fr;
      }
    }

    /* Confirmation Modal */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.6);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }

    .modal-overlay[aria-hidden="false"] {
      display: flex;
    }

    .modal-card {
      background: var(--c-bg-alt);
      border: 1px solid var(--c-border-soft);
      border-radius: var(--radius);
      box-shadow: var(--shadow-elev);
      width: min(560px, 92vw);
      padding: 1rem 1.1rem 1.1rem;
      color: var(--c-text);
    }

    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .5rem;
      margin-bottom: .6rem;
    }

    .modal-title {
      margin: 0;
      font-size: 1.05rem;
      font-weight: 600;
      letter-spacing: .4px;
    }

    .modal-body {
      font-size: .8rem;
      color: var(--c-text-soft);
    }

    .modal-summary {
      display: grid;
      grid-template-columns: 120px 1fr;
      gap: .45rem .75rem;
      margin: .6rem 0 .2rem;
    }

    .modal-actions {
      display: flex;
      gap: .6rem;
      justify-content: flex-end;
      margin-top: .9rem;
    }

    .btn-ghost {
      background: var(--c-surface);
      color: var(--c-text-soft);
      border: 1px solid var(--c-border);
      padding: 0.5rem 0.85rem;
      border-radius: var(--radius-sm);
      font-weight: 600;
      letter-spacing: .4px;
      cursor: pointer;
    }

    .btn-ghost:hover {
      color: var(--c-text);
      border-color: var(--c-accent-alt);
    }

    .btn-accent {
      background: var(--grad-accent);
      color: #111827;
      border: 0;
      padding: 0.55rem 1rem;
      border-radius: var(--radius-sm);
      font-weight: 800;
      letter-spacing: .45px;
      cursor: pointer;
      min-width: 160px;
    }

    .btn-accent:disabled {
      opacity: .6;
      cursor: not-allowed;
    }

    /* Messenger-style chat tweaks (size unchanged) */
    #bookingChat .chat-messages {
      position: relative;
      font-family: inherit;
    }

    #bookingChat .chat-line {
      display: flex;
      flex-direction: column;
      max-width: 78%;
    }

    #bookingChat .chat-line.chat-customer {
      /* current user */
      align-self: flex-end;
      text-align: right;
    }

    #bookingChat .chat-line.chat-owner {
      /* shop owner */
      align-self: flex-start;
      text-align: left;
    }

    #bookingChat .chat-line .chat-meta {
      font-size: .48rem;
      letter-spacing: .5px;
      opacity: .65;
      margin: 0 4px 3px;
      font-weight: 500;
      line-height: 1.1;
    }

    #bookingChat .chat-line .chat-text {
      background: #1e2732;
      border: 1px solid #283543;
      padding: .45rem .6rem .5rem;
      border-radius: 14px;
      font-size: .63rem;
      line-height: 1.35;
      color: #e2e8f0;
      box-shadow: 0 2px 4px -2px rgba(0, 0, 0, .45), 0 4px 12px -4px rgba(0, 0, 0, .35);
      word-break: break-word;
    }

    #bookingChat .chat-line.chat-customer .chat-text {
      background: linear-gradient(135deg, #f59e0b, #fbbf24);
      border: 1px solid #d97706;
      /* darker edge for contrast */
      color: #1b1f24;
      /* dark text for readability on bright gradient */
    }

    #bookingChat .chat-line.chat-owner .chat-text {
      background: #1b2530;
      border: 1px solid #2a3a43;
      color: #f1f5f9;
    }

    /* Subtle hover (optional) */
    #bookingChat .chat-line .chat-text:hover {
      filter: brightness(1.05);
    }
  </style>
</head>

<body class="dashboard-wrapper">
  <header class="header-bar">
    <button class="nav-hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="customerNav">☰</button>
    <div class="header-brand">
      <span>BarberSure <span style="opacity:.55;font-weight:500;">Customer</span></span>
      <span class="header-badge">Welcome<?= $user ? ', ' . e(explode(' ', trim($user['full_name']))[0]) : '' ?></span>
    </div>
    <nav id="customerNav" class="nav-links">
      <a href="dashboard.php">Dashboard</a>
      <a href="search.php">Find Shops</a>
      <a href="bookings_history.php">History</a>
      <a href="profile.php">Profile</a>
      <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
        <button type="submit" class="logout-btn">Logout</button>
      </form>
    </nav>
  </header>
  <main class="dashboard-main">
    <div class="page-container">
      <section class="card" style="padding:1.3rem 1.4rem 1.55rem;margin-bottom:1.6rem;">
        <div class="search-header" style="margin-bottom:1rem;">
          <h1 style="font-size:1.55rem;margin:0;font-weight:600;letter-spacing:.4px;">Create Appointment</h1>
          <p style="font-size:.8rem;color:var(--c-text-soft);max-width:760px;line-height:1.55;margin:.45rem 0 0;">Choose a barbershop, pick a service, and schedule your preferred time. You can add optional notes for special requests.</p>
        </div>
      </section>
      <div class="layout two-col">
        <div class="primary-col section">
          <h2 style="display:none;">Create Appointment</h2>
          <?php if ($needsPhone || $errors || $success): ?>
            <div class="toast-container" aria-live="polite" aria-atomic="true" style="margin-bottom:.4rem;">
              <?php if ($needsPhone): ?>
                <div class="toast toast-error" role="alert" data-duration="7000">
                  <div class="toast-icon" aria-hidden="true">⚠️</div>
                  <div class="toast-body">Profile not completed yet — please add your phone number to continue booking.</div>
                  <button class="toast-close" aria-label="Close notification">&times;</button>
                  <div class="toast-progress"></div>
                </div>
              <?php endif; ?>
              <?php if ($success): ?>
                <div class="toast" data-duration="6000" role="alert">
                  <div class="toast-icon" aria-hidden="true">✅</div>
                  <div class="toast-body"><?= e($success) ?></div>
                  <button class="toast-close" aria-label="Close notification">&times;</button>
                  <div class="toast-progress"></div>
                </div>
              <?php endif; ?>
              <?php if ($errors): foreach ($errors as $er): ?>
                  <div class="toast toast-error" data-duration="9000" role="alert">
                    <div class="toast-icon" aria-hidden="true">⚠️</div>
                    <div class="toast-body"><?= e($er) ?></div>
                    <button class="toast-close" aria-label="Close error">&times;</button>
                    <div class="toast-progress"></div>
                  </div>
              <?php endforeach;
              endif; ?>
            </div>
          <?php endif; ?>
          <form method="post" class="booking-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
            <div class="field-grid">
              <div>
                <label class="field-label">Barbershop</label>
                <?php
                $selectedShopName = '—';
                if ($selectedShopId) {
                  foreach ($shops as $s) {
                    if ((int)$s['shop_id'] === (int)$selectedShopId) {
                      $selectedShopName = $s['shop_name'] . ($s['city'] ? ' • ' . $s['city'] : '');
                      break;
                    }
                  }
                }
                ?>
                <input type="text" class="control" value="<?= e($selectedShopName) ?>" readonly aria-readonly="true" />
                <input type="hidden" name="shop_id" value="<?= (int)$selectedShopId ?>" />
                <p class="muted" style="margin:.3rem 0 0;">Barbershops will show here.</p>
              </div>
              <div>
                <label class="field-label">Date & Time</label>
                <input type="datetime-local" name="appointment_date" id="appointment_dt" class="control" value="<?= e($_POST['appointment_date'] ?? '') ?>" />
                <?php
                // small formatter to 12-hour display
                $fmtHr = function ($t) {
                  if (!$t) return null;
                  if (preg_match('/^\d{2}:\d{2}$/', $t)) $t .= ':00';
                  $ts = strtotime($t);
                  return $ts ? date('g:i A', $ts) : $t;
                };
                if (!empty($shopHours)) {
                  $o = $shopHours['open_time'] ?? null;
                  $c = $shopHours['close_time'] ?? null;
                  if (!empty($o) && !empty($c)):
                ?>
                    <div class="hours-hint"><i class="bi bi-clock"></i> Open today: <?= e($fmtHr($o)) ?> – <?= e($fmtHr($c)) ?></div>
                <?php
                  endif;
                }
                ?>
              </div>
              <div>
                <label class="field-label">Payment</label>
                <select name="payment_option" class="control">
                  <option value="cash" <?= (($_POST['payment_option'] ?? '') === 'cash') ? 'selected' : '' ?>>Cash</option>
                  <option value="online" <?= (($_POST['payment_option'] ?? '') === 'online') ? 'selected' : '' ?>>Online</option>
                </select>
              </div>
            </div>
            <div>
              <label class="field-label">Notes (Optional)</label>
              <textarea name="notes" class="control notes-box" placeholder="Any specific instructions?"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
            <div>
              <label class="field-label" style="display:flex;align-items:center;gap:.4rem;">Service <span class="muted" style="font-weight:400;">(select one)</span></label>
              <?php if (!$selectedShopId): ?>
                <div class="loading-msg">Select a barbershop to load its services.</div>
              <?php elseif (!$services): ?>
                <div class="loading-msg">No services configured for this shop.</div>
              <?php else: ?>
                <div class="services-list">
                  <?php foreach ($services as $svc): $sid = (int)$svc['service_id'];
                    $active = ((int)($_POST['service_id'] ?? 0) === $sid); ?>
                    <label class="service-item<?= $active ? ' active' : '' ?>">
                      <input type="radio" name="service_id" value="<?= $sid ?>" class="service-radio" <?= $active ? 'checked' : '' ?> />
                      <span style="font-weight:600;letter-spacing:.3px;font-size:1rem;"><?= e($svc['service_name']) ?></span>
                      <span style="display:flex;justify-content:space-between;align-items:center;font-size:0.9rem;margin-top:4px;">
                        <span><?= (int)$svc['duration_minutes'] ?> mins</span>
                        <span class="badge-pill">₱<?= number_format((float)$svc['price'], 2) ?></span>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="actions">
              <button type="submit" class="btn btn-primary" style="font-size:1rem;padding:12px 24px;">Book Appointment</button>
              <a href="booking.php" class="btn" style="font-size:1rem;padding:12px 24px;">Reset</a>
            </div>
          </form>
          <section class="section" id="card-fields-section" style="margin-top:1rem;<?= ((($_POST['payment_option'] ?? '') === 'online') ? '' : 'display:none;') ?>">
            <h2 style="display:flex;align-items:center;gap:.4rem;"><i class="bi bi-credit-card"></i> Card details</h2>
            <div class="field-grid">
              <div>
                <label class="field-label">Card number</label>
                <input id="card-number" name="card_number" type="text" class="control" inputmode="numeric" autocomplete="cc-number" placeholder="1234 5678 9012 3456" maxlength="19" />
              </div>
              <div>
                <label class="field-label">Expiration</label>
                <input id="card-exp" name="card_exp" type="text" class="control" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/YY" maxlength="5" />
              </div>
              <div>
                <label class="field-label">Security code</label>
                <input id="card-cvc" name="card_cvc" type="text" class="control" inputmode="numeric" autocomplete="cc-csc" placeholder="CVC" maxlength="4" />
              </div>
              <div>
                <label class="field-label">Country</label>
                <select id="card-country" name="card_country" class="control">
                  <option value="PH">Philippines</option>
                  <option value="US">United States</option>
                  <option value="CA">Canada</option>
                  <option value="GB">United Kingdom</option>
                  <option value="AU">Australia</option>
                  <option value="SG">Singapore</option>
                  <option value="MY">Malaysia</option>
                </select>
              </div>
            </div>
            <div id="card-error" style="display:none;color:#ef4444;font-size:.78rem;margin-top:.35rem;">Please fill in all card details to book with online payment.</div>
            <p class="muted" style="margin-top:.5rem;font-size:.7rem;">Note: This is a demo UI only and does not process payments.</p>
          </section>
        </div>
        <div class="summary-col sticky-note" aria-live="polite">
          <h2 class="live-summary-title">Summary</h2>
          <div class="live-summary" id="live-summary">
            <div><strong>Shop</strong></div>
            <div id="ls-shop">—</div>
            <div><strong>Service</strong></div>
            <div id="ls-service">—</div>
            <div><strong>Date & Time</strong></div>
            <div id="ls-datetime">—</div>
            <div><strong>Payment</strong></div>
            <div id="ls-payment">Cash</div>
            <div><strong>Notes</strong></div>
            <div id="ls-notes">—</div>
          </div>
          <p class="summary-hint">This summary updates automatically as you fill out the form. You can still review everything in the confirmation step before submitting.</p>
          <?php
          // Use pre-derived booking page channel (session + selected shop)
          $channel = $bookingPageChannel;
          ?>
          <div id="bookingChat" class="card" data-channel="<?= e($channel) ?>" style="margin-top:1.2rem;padding:0.9rem 0.95rem 1rem;">
            <h3 style="margin:0 0 .6rem;font-size:.95rem;font-weight:600;display:flex;align-items:center;gap:.5rem;">Chat with Owner <span style="font-size:.55rem;letter-spacing:.5px;font-weight:600;background:#1f2a36;padding:.25rem .5rem;border-radius:40px;">Ephemeral</span></h3>
            <div class="chat-messages" style="background:var(--c-surface);border:1px solid var(--c-border-soft);border-radius:var(--radius-sm);padding:.6rem .65rem;display:flex;flex-direction:column;gap:.55rem;height:220px;overflow-y:auto;font-size:.65rem;line-height:1.35;">
              <div style="text-align:center;font-size:.6rem;color:var(--c-text-soft);">Messages are temporary and not stored permanently.</div>
            </div>
            <form style="margin-top:.65rem;display:flex;flex-direction:column;gap:.5rem;">
              <textarea rows="2" placeholder="Type a message to the shop owner…" style="background:var(--c-surface);border:1px solid var(--c-border);color:var(--c-text);border-radius:var(--radius-sm);padding:.55rem .6rem;font-size:.65rem;resize:vertical;min-height:54px;max-height:140px;"></textarea>
              <div style="display:flex;gap:.5rem;align-items:center;">
                <button type="submit" class="btn btn-primary" style="font-size:.65rem;padding:.55rem 1rem;">Send</button>
                <span style="font-size:.55rem;color:var(--c-text-soft);">Not saved • Auto-clears after inactivity</span>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div> <!-- /.page-container -->
  </main>
  <footer class="dashboard-footer">&copy; <?= date('Y') ?> BarberSure • Book with confidence.</footer>
  <!-- Confirmation Modal -->
  <div id="confirm-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="confirm-title">
    <div class="modal-card">
      <div class="modal-header">
        <h3 id="confirm-title" class="modal-title">Confirm Your Booking</h3>
        <span class="badge-pill">Review</span>
      </div>
      <div class="modal-body">
        <div class="modal-summary">
          <div><strong>Shop</strong></div>
          <div id="sum-shop">—</div>
          <div><strong>Service</strong></div>
          <div id="sum-service">—</div>
          <div><strong>Date & Time</strong></div>
          <div id="sum-datetime">—</div>
          <div><strong>Payment</strong></div>
          <div id="sum-payment">—</div>
          <div><strong>Notes</strong></div>
          <div id="sum-notes">—</div>
        </div>
        <div class="muted">Please verify the details are correct. You can still go back to make changes.</div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-ghost" id="confirm-cancel">Cancel</button>
        <button type="button" class="btn-accent" id="confirm-submit" disabled>Confirm (3)</button>
      </div>
    </div>
  </div>

  <script>
    // Client-side validation: enforce future date and shop hours (runs after DOM built)
    (function() {
      const dtInput = document.getElementById('appointment_dt');
      if (!dtInput) return;
      const pad = n => String(n).padStart(2, '0');
      const now = new Date();
      now.setMinutes(now.getMinutes() + (5 - (now.getMinutes() % 5)) % 5, 0, 0);
      const minVal = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
      if (!dtInput.min) dtInput.min = minVal;
      const shopOpen = <?= isset($shopHours['open_time']) ? ('"' . addslashes($shopHours['open_time']) . '"') : 'null' ?>;
      const shopClose = <?= isset($shopHours['close_time']) ? ('"' . addslashes($shopHours['close_time']) . '"') : 'null' ?>;

      function norm(t) {
        if (!t) return null;
        return /^\d{2}:\d{2}$/.test(t) ? t + ':00' : t;
      }

      function inHours(d) {
        if (!shopOpen || !shopClose) return true;
        const o = norm(shopOpen),
          c = norm(shopClose);
        if (!o || !c) return true;
        const day = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
        const oD = new Date(`${day}T${o}`);
        const cD = new Date(`${day}T${c}`);
        return d >= oD && d < cD;
      }

      function validate() {
        if (!dtInput.value) {
          dtInput.setCustomValidity('');
          return;
        }
        const d = new Date(dtInput.value);
        if (isNaN(d.getTime())) {
          dtInput.setCustomValidity('Invalid date');
          return;
        }
        const nowLocal = new Date();
        if (d < nowLocal) dtInput.setCustomValidity('Time already passed');
        else if (!inHours(d)) dtInput.setCustomValidity('Outside shop hours');
        else dtInput.setCustomValidity('');
      }
      dtInput.addEventListener('change', validate);
      dtInput.addEventListener('input', validate);
      validate();
    })();
  </script>
  <script>
    // Intercept submit to show confirmation with 3s delay before enabling confirm
    (function() {
      const form = document.querySelector('form.booking-form');
      if (!form) return;
      let bypass = false;

      const overlay = document.getElementById('confirm-overlay');
      const btnCancel = document.getElementById('confirm-cancel');
      const btnConfirm = document.getElementById('confirm-submit');
      const elShop = document.getElementById('sum-shop');
      const elSvc = document.getElementById('sum-service');
      const elDt = document.getElementById('sum-datetime');
      const elPay = document.getElementById('sum-payment');
      const elNotes = document.getElementById('sum-notes');

      function getShopName() {
        // The read-only shop input is the first readonly .control in the form
        const ro = form.querySelector('input.control[readonly]');
        return ro ? ro.value.trim() || '—' : '—';
      }

      function getServiceText() {
        const checked = form.querySelector('input[name="service_id"]:checked');
        if (!checked) return '—';
        const wrap = checked.closest('.service-item');
        if (!wrap) return '—';
        const spans = wrap.querySelectorAll('span');
        // First span is name; second line has price badge later
        const name = spans[0] ? spans[0].textContent.trim() : '';
        const price = wrap.querySelector('.badge-pill')?.textContent.trim() || '';
        return price ? name + ' • ' + price : name || '—';
      }

      function formatDateTime(v) {
        if (!v) return '—';
        try {
          // v is like '2025-09-24T14:30'
          const dt = new Date(v);
          if (isNaN(dt.getTime())) return v;
          const opts = {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
          };
          return dt.toLocaleString(undefined, opts);
        } catch (e) {
          return v;
        }
      }

      function populateSummary() {
        elShop.textContent = getShopName();
        elSvc.textContent = getServiceText();
        elDt.textContent = formatDateTime(form.elements['appointment_date']?.value || '');
        const paySel = form.elements['payment_option'];
        const payText = paySel && paySel.options[paySel.selectedIndex] ? paySel.options[paySel.selectedIndex].text : '—';
        elPay.textContent = payText;
        const notes = form.elements['notes']?.value.trim() || '';
        elNotes.textContent = notes !== '' ? notes : '—';

        // Mirror to live summary panel
        const lsShop = document.getElementById('ls-shop');
        const lsSvc = document.getElementById('ls-service');
        const lsDt = document.getElementById('ls-datetime');
        const lsPay = document.getElementById('ls-payment');
        const lsNotes = document.getElementById('ls-notes');
        if (lsShop) lsShop.textContent = elShop.textContent;
        if (lsSvc) lsSvc.textContent = elSvc.textContent;
        if (lsDt) lsDt.textContent = elDt.textContent;
        if (lsPay) lsPay.textContent = elPay.textContent;
        if (lsNotes) lsNotes.textContent = elNotes.textContent;
      }

      function openModal() {
        populateSummary();
        overlay.setAttribute('aria-hidden', 'false');
        // Countdown
        let left = 3;
        btnConfirm.disabled = true;
        btnConfirm.textContent = `Confirm (${left})`;
        const t = setInterval(() => {
          left -= 1;
          if (left <= 0) {
            clearInterval(t);
            btnConfirm.disabled = false;
            btnConfirm.textContent = 'Confirm Booking';
          } else {
            btnConfirm.textContent = `Confirm (${left})`;
          }
        }, 1000);
      }

      function closeModal() {
        overlay.setAttribute('aria-hidden', 'true');
      }

      function isValidCard() {
        const paySel = form.elements['payment_option'];
        const pay = paySel ? paySel.value : 'cash';
        if (pay !== 'online') return true;
        const num = document.getElementById('card-number')?.value || '';
        const exp = document.getElementById('card-exp')?.value || '';
        const cvc = document.getElementById('card-cvc')?.value || '';
        const country = document.getElementById('card-country')?.value || '';
        const showError = (msg) => {
          const el = document.getElementById('card-error');
          if (!el) return;
          el.textContent = msg || 'Please fill in all card details to book with online payment.';
          el.style.display = '';
        };
        const hideError = () => {
          const el = document.getElementById('card-error');
          if (el) el.style.display = 'none';
        };
        // Basic formatting checks (UI-only)
        const digits = (num || '').replace(/\D+/g, '');
        if (digits.length < 16) {
          showError('Card number must be 16 digits.');
          return false;
        }
        if (!/^\d{2}\/\d{2}$/.test(exp)) {
          showError('Expiration must be in MM/YY format.');
          return false;
        }
        const [mm, yy] = exp.split('/');
        const m = parseInt(mm, 10);
        if (!(m >= 1 && m <= 12)) {
          showError('Expiration month must be between 01 and 12.');
          return false;
        }
        // Expiration must not be in the past (compare end of month)
        const now = new Date();
        const curY = now.getFullYear();
        const curM = now.getMonth() + 1; // 1-12
        const y = 2000 + parseInt(yy, 10); // assume 20xx
        if (isNaN(y) || y < 2000 || y > 2099) {
          showError('Invalid expiration year.');
          return false;
        }
        if (y < curY || (y === curY && m < curM)) {
          showError('Card has expired.');
          return false;
        }
        if (!/^\d{3,4}$/.test(cvc)) {
          showError('CVC must be 3 or 4 digits.');
          return false;
        }
        if (!country) {
          showError('Please select a country.');
          return false;
        }
        hideError();
        return true;
      }

      form.addEventListener('submit', function(e) {
        if (bypass) return; // allow actual submit after confirm

        // Basic client-side check: require date and service to show modal; otherwise let server validate
        const hasDate = !!(form.elements['appointment_date']?.value);
        const hasService = !!form.querySelector('input[name="service_id"]:checked');
        if (!hasDate || !hasService) {
          return; // allow normal submit to get server-side errors
        }

        // If online payment selected, enforce card details before showing modal
        if (!isValidCard()) {
          e.preventDefault();
          return;
        }

        e.preventDefault();
        openModal();
      });

      btnCancel?.addEventListener('click', () => closeModal());
      overlay?.addEventListener('click', (ev) => {
        if (ev.target === overlay) closeModal();
      });
      btnConfirm?.addEventListener('click', () => {
        if (btnConfirm.disabled) return;
        // Re-check validity before final submit
        if (!isValidCard()) return;
        bypass = true;
        closeModal();
        form.submit();
      });

      // Live listeners for instant summary updates
      ['change', 'input'].forEach(evt => {
        form.addEventListener(evt, (e) => {
          if (e.target.matches('input[name="service_id"], select[name="payment_option"], textarea[name="notes"], input[name="appointment_date"]')) {
            populateSummary();
          }
        });
      });

      // Initial population
      populateSummary();

      // Live re-validation for card fields to clear error as user fixes input
      // Live re-validation for card fields (outside form), do not validate on payment option change to avoid premature errors
      const cardNumEl = document.getElementById('card-number');
      const cardExpEl = document.getElementById('card-exp');
      const cardCvcEl = document.getElementById('card-cvc');
      const cardCountryEl = document.getElementById('card-country');
      cardNumEl?.addEventListener('input', () => {
        isValidCard();
      });
      cardExpEl?.addEventListener('input', () => {
        isValidCard();
      });
      cardCvcEl?.addEventListener('input', () => {
        isValidCard();
      });
      cardCountryEl?.addEventListener('change', () => {
        isValidCard();
      });
    })();
  </script>
  <script>
    // Make clicking service label toggle active state instantly when choosing
    document.querySelectorAll('.service-radio').forEach(r => {
      r.addEventListener('change', () => {
        document.querySelectorAll('.service-item').forEach(i => i.classList.remove('active'));
        const wrap = r.closest('.service-item');
        if (wrap) wrap.classList.add('active');
      });
    });
  </script>
  <script>
    // Lightweight toast behavior (reuses CSS in assets/css/toast.css)
    (function() {
      const cont = document.querySelector('.toast-container');
      if (!cont) return;
      cont.querySelectorAll('.toast').forEach(t => {
        const btn = t.querySelector('.toast-close');
        const dur = parseInt(t.getAttribute('data-duration') || '5000', 10);
        let timer;

        function close() {
          t.style.display = 'none';
        }
        if (btn) btn.addEventListener('click', close);
        if (dur > 0) timer = setTimeout(close, dur);
      });
    })();
  </script>
  <?php if ($needsPhone): ?>
    <script>
      // After ~7 seconds, send the user to profile to complete phone, preserving return
      (function() {
        const to = 'profile.php?require=phone&from=<?= e(rawurlencode('customer/booking.php' . ($selectedShopId ? ('?shop=' . (int)$selectedShopId) : ''))) ?>';
        setTimeout(() => {
          window.location.href = to;
        }, 7000);
      })();
    </script>
  <?php endif; ?>
  <script src="../assets/js/menu-toggle.js"></script>
  <script src="../assets/js/booking_chat.js"></script>
  <script>
    // Toggle plain card fields section when Online payment is selected
    (function() {
      const select = document.querySelector('select[name="payment_option"]');
      const cardSection = document.getElementById('card-fields-section');

      function update() {
        if (!select || !cardSection) return;
        cardSection.style.display = (select.value === 'online') ? '' : 'none';
        // Clear any existing card error when toggling payment option
        const err = document.getElementById('card-error');
        if (err) err.style.display = 'none';
      }
      if (select) {
        select.addEventListener('change', update);
        update();
      }
    })();
  </script>
  <script>
    // Card input formatting: number groups of 4 and MM/YY for expiration
    (function() {
      const num = document.getElementById('card-number');
      const exp = document.getElementById('card-exp');

      function formatCardNumber(value) {
        const digits = value.replace(/\D+/g, '').slice(0, 16); // 16 digits max
        return digits.replace(/(.{4})/g, '$1 ').trim();
      }

      function onNumberInput(e) {
        const el = e.target;
        const start = el.selectionStart;
        const prev = el.value;
        const formatted = formatCardNumber(prev);
        el.value = formatted;
        // Try to preserve caret position based on number of spaces before the cursor
        const digitsBefore = prev.slice(0, start).replace(/\D+/g, '').length;
        let caret = digitsBefore + Math.floor(Math.max(digitsBefore - 1, 0) / 4);
        // Clamp
        caret = Math.min(caret, el.value.length);
        requestAnimationFrame(() => el.setSelectionRange(caret, caret));
      }

      function formatExp(value) {
        const digits = value.replace(/\D+/g, '').slice(0, 4);
        if (digits.length === 0) return '';
        if (digits.length <= 2) return digits;
        return digits.slice(0, 2) + '/' + digits.slice(2);
      }

      function onExpInput(e) {
        const el = e.target;
        const start = el.selectionStart;
        const prev = el.value;
        const formatted = formatExp(prev);
        el.value = formatted;
        // Caret adjust: if we inserted a '/', move cursor accordingly
        const digitsBefore = prev.slice(0, start).replace(/\D+/g, '').length;
        let caret = digitsBefore + (digitsBefore > 2 ? 1 : 0);
        caret = Math.min(caret, el.value.length);
        requestAnimationFrame(() => el.setSelectionRange(caret, caret));
      }

      num?.addEventListener('input', onNumberInput);
      exp?.addEventListener('input', onExpInput);

      // Basic constraints for CVC (numeric only)
      const cvc = document.getElementById('card-cvc');
      cvc?.addEventListener('input', (e) => {
        const el = e.target;
        const digits = el.value.replace(/\D+/g, '').slice(0, 4);
        el.value = digits;
      });
    })();
  </script>
</body>

</html>