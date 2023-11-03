<?php
$title = 'Статус пользователя';
include_once($_SERVER["DOCUMENT_ROOT"].'/style/head.php');
if(!isset($User)){
    header('location:/');
}
?>
    <div class="container">
        <div class="row">
            <div class="col-sm-3">
                <ul class="list-group">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Элемент списка
                        <span class="badge bg-primary rounded-pill">14</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Второй элемент списка
                        <span class="badge bg-primary rounded-pill">2</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Третий элемент списка
                        <span class="badge bg-primary rounded-pill">1</span>
                    </li>
                </ul>
            </div>
            <div class="col-sm-9">
                <div class="row">
                    <div class="alert alert-warning" role="alert">
                        Простое уведомление warning — проверьте!
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php
include_once($_SERVER["DOCUMENT_ROOT"].'/style/foot.php');
?>