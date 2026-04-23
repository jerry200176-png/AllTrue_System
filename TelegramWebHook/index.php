<?php
/**
 * Telegram Webhook：支援多分校
 *
 * 用法：
 * - 新版：/TelegramWebHook/index.php?code=daan   (Campus.code)
 * - 相容舊參數：/TelegramWebHook/index.php?Token=daan
 */

header('Content-Type: text/plain; charset=utf-8');

include("../fun/db.php");

$campusCode = '';
if (isset($_GET['code']) && is_string($_GET['code'])) {
    $campusCode = trim($_GET['code']);
}
if ($campusCode === '' && isset($_GET['Token']) && is_string($_GET['Token'])) {
    $campusCode = trim($_GET['Token']);
}

if ($campusCode === '') {
    http_response_code(400);
    echo 'Missing campus code. Please use ?code=campus_code';
    exit;
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);
if (!is_array($update) || !isset($update["message"]["chat"]["id"])) {
    echo 'ok';
    exit;
}

$chatId = (string) $update["message"]["chat"]["id"];
$recvMsgText = isset($update["message"]["text"]) ? trim((string) $update["message"]["text"]) : '';

try {
    $db = new PDO(
        "mysql:host=".$hostname.";dbname=".$db_name,
        $username,
        $password,
        array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8")
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log(date("Y-m-d H:i:s")." 無法開啟資料庫: ".$e->getMessage()."\n", 3, "Log/".date("Y-m-d").".log");
    http_response_code(500);
    echo 'db error';
    exit;
}

try {
    $sql = "SELECT id, name, code, TelegramToken FROM Campus WHERE code = :code LIMIT 1";
    $stm = $db->prepare($sql);
    $stm->bindParam(":code", $campusCode, PDO::PARAM_STR);
    $stm->execute();
    $campus = $stm->fetch(PDO::FETCH_ASSOC);

    if (!$campus && ctype_digit($campusCode)) {
        $sql = "SELECT id, name, code, TelegramToken FROM Campus WHERE id = :id LIMIT 1";
        $stm = $db->prepare($sql);
        $campusId = (int) $campusCode;
        $stm->bindParam(":id", $campusId, PDO::PARAM_INT);
        $stm->execute();
        $campus = $stm->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log(date("Y-m-d H:i:s")." Select Campus ".$e->getMessage()."\n", 3, "Log/".date("Y-m-d").".log");
    http_response_code(500);
    echo 'campus query error';
    exit;
}

if (!$campus) {
    http_response_code(404);
    echo 'Unknown campus';
    exit;
}

$botToken = isset($campus["TelegramToken"]) ? trim((string) $campus["TelegramToken"]) : '';
if ($botToken === '') {
    http_response_code(503);
    echo 'Telegram token not configured';
    exit;
}

if ($recvMsgText === '' || strcasecmp($recvMsgText, '/start') === 0) {
    sendMessage($chatId, "歡迎使用 ".$campus["name"]." 通知綁定，請輸入學生姓名。", $botToken);
    echo 'ok';
    exit;
}

try {
    $sql = "SELECT id, name, TelegramID, TelegramID1, TelegramID2
            FROM Student
            WHERE name = :name AND enable = 1 AND CampusID = :campusId
            LIMIT 2";
    $stm = $db->prepare($sql);
    $stm->bindParam(":name", $recvMsgText, PDO::PARAM_STR);
    $campusId = (int) $campus["id"];
    $stm->bindParam(":campusId", $campusId, PDO::PARAM_INT);
    $stm->execute();
    $students = $stm->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(date("Y-m-d H:i:s")." Select Student ".$e->getMessage()."\n", 3, "Log/".date("Y-m-d").".log");
    sendMessage($chatId, "系統忙碌中，請稍後再試。", $botToken);
    echo 'ok';
    exit;
}

if (!$students || count($students) === 0) {
    sendMessage($chatId, "查無此學生：".$recvMsgText, $botToken);
    echo 'ok';
    exit;
}

if (count($students) > 1) {
    sendMessage($chatId, "校內有多位同名學生，請洽櫃台協助綁定。", $botToken);
    echo 'ok';
    exit;
}

$student = $students[0];
$t0 = isset($student["TelegramID"]) ? (string) $student["TelegramID"] : '';
$t1 = isset($student["TelegramID1"]) ? (string) $student["TelegramID1"] : '';
$t2 = isset($student["TelegramID2"]) ? (string) $student["TelegramID2"] : '';

if ($t0 === $chatId || $t1 === $chatId || $t2 === $chatId) {
    sendMessage($chatId, $recvMsgText." 已綁定此 Telegram", $botToken);
    echo 'ok';
    exit;
}

try {
    $sql = "UPDATE Student SET MDT = NOW()";
    if ($t0 === '') {
        $sql .= ", TelegramID = :telegramId";
    } elseif ($t1 === '') {
        $sql .= ", TelegramID1 = :telegramId";
    } elseif ($t2 === '') {
        $sql .= ", TelegramID2 = :telegramId";
    } else {
        sendMessage($chatId, $recvMsgText." 的通知名額已滿（最多三個 Telegram）", $botToken);
        echo 'ok';
        exit;
    }
    $sql .= " WHERE id = :studentId";

    $stm = $db->prepare($sql);
    $stm->bindParam(":telegramId", $chatId, PDO::PARAM_STR);
    $studentId = (int) $student["id"];
    $stm->bindParam(":studentId", $studentId, PDO::PARAM_INT);
    $stm->execute();

    sendMessage($chatId, $recvMsgText." 綁定成功", $botToken);
} catch (PDOException $e) {
    error_log(date("Y-m-d H:i:s")." Update Student ".$e->getMessage()."\n", 3, "Log/".date("Y-m-d").".log");
    sendMessage($chatId, $recvMsgText." 綁定失敗，請稍後再試", $botToken);
}

echo 'ok';

function sendMessage($chatId, $message, $botToken) {
    $url = "https://api.telegram.org/bot".$botToken."/sendMessage";
    $data = array(
        'chat_id' => $chatId,
        'text' => $message
    );
    $options = array(
        'http' => array(
            'header'  => "Content-Type: application/json",
            'method'  => 'POST',
            'content' => json_encode($data),
        ),
    );
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

?>