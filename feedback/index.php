<?php
header('Content-Type: application/json');
// обработка только ajax запросов (при других запросах завершаем выполнение скрипта)
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest') {
    exit();
}
// обработка данных, посланных только методом POST (при остальных методах завершаем выполнение скрипта)
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    exit();
}

$ref = $_SERVER['HTTP_REFERER'];

$messages = array(
    'validate' => array(
        'required' => 'Поле %1$s обязательно к заполнению',
        'minlength' => 'Минимальная длинна поля %1$s меньше допустимой - %2$s',
        'maxlength' => 'Максимальная длинна поля %1$s превышает допустимую - %2$s',
        'preg' => 'Поле %1$s возможно содержит ошибку',
        'extensions' => 'Допускаются файлы с расширением %1$s',
        'maxsize' => 'Максимальный размер файлов %1$s',
        'empty' => 'Поле %1$s должно быть пустым',
    ),
);

$defaultConfig = array(
    'fields' => array(
        'name' => array(
            'title' => 'Имя',
            'validate' => array(
                'preg' => '%[A-Z-a-zА-Яа-я\s]%',
                'minlength' => '2',
            ),
            'messages' => array(
                'minlength' => 'Имя должно быть длиннее 2 символов',
            )
        ),
        'phone' => array(
            'title' => 'Телефон',
            'validate' => array(
                'required' => true,
            ),
        ),
        'email' => array(
            'title' => 'Email',
            'validate' => array(
                'required' => true,
                'preg' => "/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,})$/",
            ),
            'messages' => array(
                'required' => 'Email обязателен для заполнения'
            )
        ),
        'message' => array(
            'title' => 'Сообщение',
        ),
        'uploaded_file' => array(
            'title' => 'Файлы',
            'file' => true,
            'validate' => array(
                'extensions' => 'jpg,tif,pdf,doc,docs',
                'maxsize' => '100000000',
            ),
        ),
    ),
    'config' => array(
        'subject' => 'Сообщение с формы обратной связи',
        'validate' => true,
        'from_email' => 'no-reply@domain.com',
        'from_name' => 'Имя сайта',
        'to_email' => ['manager@domain.com'],
        'tpl' => 'feedback',
        'captcha' => false,
        'log' => true,
        'smtp' => false,
        'smtp_host' => 'smtp.gmail.com',
        'smtp_login' => 'no-reply@domain.com',
        'smtp_port' => 587,
        'smtp_password' => 'password',
        'smtp_secure' => 'tls',
    )
);

$forms = [
    'feedback' => $defaultConfig,
];


// 2 ЭТАП - ПОДКЛЮЧЕНИЕ PHPMAILER
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once('phpmailer/src/Exception.php');
require_once('phpmailer/src/PHPMailer.php');
require_once('phpmailer/src/SMTP.php');


// 3 ЭТАП - ОТКРЫТИЕ СЕССИИ И ИНИЦИАЛИЗАЦИЯ ПЕРЕМЕННОЙ ДЛЯ ХРАНЕНИЯ РЕЗУЛЬТАТОВ ОБРАБОТКИ ФОРМЫ
session_start();

$data = [
    'errors' => [],
    'success' => true,
    'logs' => [],
    'fields' => [],
    'message' => '',
];
$attaches = [];
$formId = htmlspecialchars($_REQUEST['form-id']) ?? 'feedback';

if (isset($forms[$formId])) {

    $form = $forms[$formId];

    foreach ($form['fields'] as $name => $field) {
        $fieldName = (isset($field['title'])) ? $field['title'] : $name;
        $def = 'Поле с именем [ ' . $fieldName . ' ] содержит ошибку.';

        if (isset($field['file']) && $field['file'] === true) {

            $files = array();

            if (!empty($_FILES[$name])) {

                if (is_array($_FILES[$name]['name'])) {
                    foreach ($_FILES[$name]['name'] as $key => $value) {
                        if ($_FILES[$name]['error'][$key] > 0) {
                            continue;
                        }

                        $files[$key]['name'] = $_FILES[$name]['name'][$key];
                        $files[$key]['size'] = $_FILES[$name]['size'][$key];
                        $files[$key]['tmp_name'] = $_FILES[$name]['tmp_name'][$key];
                        $files[$key]['type'] = $_FILES[$name]['type'][$key];
                        $files[$key]['error'] = $_FILES[$name]['error'][$key];
                    }
                } else {
                    if ($_FILES[$name]['error'] == 0) {
                        $files[] = $_FILES[$name];
                    }
                }
            }

            if (isset($field['validate']) && $form['config']['validate']) {

                if (isset($field['validate']['required']) && empty($files)) {
                    $message = getErrorMessageTemplate('required', $field);
                    $data['errors'][$name][] = (!empty($message)) ? sprintf($message, $fieldName) : $def;
                    continue;
                }

                if (!empty($files) && isset($field['validate']['maxsize'])) {
                    foreach ($files as $index => $file) {
                        if (intval($file['size']) > $field['validate']['maxsize']) {
                            $message = getErrorMessageTemplate('maxsize', $field);
                            $data['errors'][$name][] = (!empty($message)) ? sprintf($message, $fieldName, $field['validate']['maxsize']) : $def;
                            continue;
                        }
                    }
                }

                if (!empty($files) && isset($field['validate']['extensions'])) {
                    foreach ($files as $index => $file) {
                        $fileName = $file['name'];

                        $extensionsArr = explode(',', $field['validate']['extensions']);
                        $ext = strtolower(substr($fileName, strpos($fileName, '.') + 1, strlen($fileName) - 1));

                        if (!in_array($ext, $extensionsArr)) {
                            $message = getErrorMessageTemplate('extensions', $field);
                            $data['errors'][$name][] = (!empty($message)) ? sprintf($message, $fieldName, $field['validate']['extensions']) : $def;
                            continue;
                        }
                    }
                }
            }

            $attaches = array_merge($attaches, $files);
            continue;
        }

        $data['fields'][$name] = [];
        $data['fields'][$name]['title'] = $fieldName;
        $rawData = isset($_POST[$name]) ? trim($_POST[$name]) : '';


        if (isset($field['validate']) && $form['config']['validate']) {

            // required
            if (isset($field['validate']['required']) &&
                empty($rawData)) {
                $message = getErrorMessageTemplate('required', $field);
                $data['errors'][$name][] = (!empty($message)) ? sprintf($message, $fieldName) : $def;
            }
            // minlength
            if (isset($field['validate']['minlength']) &&
                mb_strlen($rawData) < $field['validate']['minlength']) {
                $message = getErrorMessageTemplate('minlength', $field);
                $data['errors'][$name][] = (!empty($message)) ? sprintf($message, $fieldName, $field['validate']['minlength']) : $def;

            }
            // maxlength
            if (isset($field['validate']['maxlength']) &&
                mb_strlen($rawData) > $field['validate']['maxlength']) {
                $message = getErrorMessageTemplate('maxlength', $field);
                $data['errors'][$name][] = (!empty($message)) ? sprintf($message, $fieldName, $field['validate']['maxlength']) : $def;
            }
            // preg
            if (isset($field['validate']['preg']) && mb_strlen($rawData) > 0 &&
                !preg_match($field['validate']['preg'], $rawData)) {
                $message = getErrorMessageTemplate('preg', $field);
                $data['errors'][$name][] = (!empty($message)) ? sprintf($message, $fieldName, $field['validate']['preg']) : $def;
            }
            // empty
            if (isset($field['validate']['empty']) &&
                !empty($rawData)) {
                $message = getErrorMessageTemplate('empty', $field);
                $data['errors'][$name][] = (!empty($message)) ? sprintf($message, $fieldName) : $def;
            }
        }

        $data['fields'][$name]['value'] = htmlspecialchars($rawData);

        if (empty($data['fields'][$name]['value'])) {
            unset($data['fields'][$name]);
        }
    }

    if ($form['config']['captcha']) {
        if (isset($_POST['captcha']) && isset($_SESSION['captcha'])) {
            $captcha = htmlspecialchars($_POST['captcha']); // защита от XSS
            if ($_SESSION['captcha'] != $captcha) { // проверка капчи
                $data['errors']['captcha'] = 'Код не соответствует изображению.';
            }
        } else {
            $data['errors']['captcha'] = 'Ошибка при проверке кода';
        }
    }

    if (!empty($data['errors'])) {
        $data['success'] = false;
    }

    if ($data['success']) {

        $bodyMail = '';

        if ($form['config']['tpl']) {
            $out = getTpl($data['fields'], $form['config']);
            if (is_string($out)) {
                $bodyMail = $out;
            }
        }

        if (mb_strlen(trim($bodyMail)) < 10) {
            if (isset($form['config']['subject'])) {
                $bodyMail .= $form['config']['subject'] . "\r\n\r\n";
            }
            foreach ($data['fields'] as $name => $item) {
                $bodyMail .= $item['title'] . ": " . $item['value'] . "\r\n";
            }
            if ($form['config']['referer']) {
                $bodyMail .= "\r\n\r\n\r\n\r\n" . $ref;
            }
        }

        // устанавливаем параметры
        $mail = new PHPMailer;
        $mail->setLanguage("ru");

        if ($form['config']['smtp']) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function($str, $level) {
                $file = __DIR__ . '/logs/smtp_' . date('Y-m-d') . '.log';
                file_put_contents($file, gmdate('Y-m-d H:i:s'). "\t$level\t$str\n", FILE_APPEND | LOCK_EX);
            };
            $mail->isSMTP();
            //Set SMTP host name
            $mail->Host = $form['config']['smtp_host'];
            //Set this to true if SMTP host requires authentication to send email
            $mail->SMTPAuth = true;
            //Provide username and password
            $mail->Username = $form['config']['smtp_login'];
            $mail->Password = $form['config']['smtp_password'];
            //If SMTP requires TLS encryption then set it
            $mail->SMTPSecure = $form['config']['smtp_secure'];
            //Set TCP port to connect to
            $mail->Port = $form['config']['smtp_port'];
        }

        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->IsHTML(true);
        $mail->setFrom($form['config']['from_email'], $form['config']['from_name']);
        $mail->Subject = $form['config']['subject'];
        $mail->Body = $bodyMail;

        foreach ($form['config']['to_email'] as $email) {
            try {
                $mail->addAddress(trim($email));
            } catch (Exception $e) {}
        }

        foreach ($attaches as $file) {
            try {
                $mail->AddAttachment($file['tmp_name'], $file['name']);
            } catch (Exception $e) {}
        }

        try {
            $mail->send();
        } catch (Exception $e) {
            $data['success'] = false;
            $data['message'] = 'Ошибка при отправке формы, попробуйте позже';
        }
    }


    if ($data['success'] && $form['config']['log']) {
        try {
            $output = "---------------------------------" . "\n";
            $output .= date("d-m-Y H:i:s") . "\n";
            foreach ($data['fields'] as $name => $item) {
                $output .= $item['title'] . ": " . $item['value'] . "\r\n";
            }
            file_put_contents(__DIR__ . '/logs/logs.log', $output, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {

        }
    }

} else {
    $data['success'] = false;
    $data['message'] = 'Нет настроек для формы #' . $formId;
}


/* ФИНАЛЬНЫЙ ЭТАП - ВОЗВРАЩАЕМ РЕЗУЛЬТАТЫ РАБОТЫ */
echo json_encode($data);

/*
 * парсер шаблона
 */
function getTpl($fields, $config)
{
    global $ref;
    $tpl = __DIR__ . '/tpl/' . $config['tpl'] . '.tpl';
    if (file_exists($tpl)) {
        $template = file_get_contents($tpl);
        foreach ($fields as $name => $field) {
            $template = str_replace(array("%" . $name . ".title%", "%" . $name . ".value%"), array($field['title'], $field['value']), $template);
        }

        $search = ['%config.subject%', '%config.date%', '%config.page_url%'];
        $replace = [$config['subject'], date('d.m.Y H:i'), $ref];

        return str_replace($search, $replace, $template);
    } else {
        return false;
    }
}

function getErrorMessageTemplate($type, $field)
{
    global $messages;
    if (!$type || !$field) {
        return '';
    }

    return $field['messages'][$type] ?? ($messages['validate'][$type] ?? '');
}
