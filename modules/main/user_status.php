<?php
$title = 'Статус пользователя';
include_once($_SERVER["DOCUMENT_ROOT"].'/style/head.php');
if(!isset($User)){
    header('location:/');
}
?>
    <!-- Модальное окно обновления доступа-->
    <div class="modal fade" id="giveAccess" tabindex="-1" aria-labelledby="giveAccessLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="giveAccessLabel">Обновление доступа ВКонтакте</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <a href="http://oauth.vk.com/oauth/authorize?client_id=51785306&scope=wall,groups,photos,audio,video,offline,pages&display=popup&redirect_uri=https://api.vk.com/blank.html&response_type=token" target="_blank">Кликните здесь</a> для получения доступа.
                    <form class="row g-3 needs-validation" id="urlForm">
                        <div class="mb-3">
                            <label for="inputUrl" class="form-label">URL:</label>
                            <input type="text" class="form-control" id="inputUrl" name="inputUrl" aria-describedby="urlHelp" placeholder="https://api.vk.com/blank.html#access_token=vk1.a.MxkO38wy74-uwQ5mF5aDUZ5KB-hOmg9tVJNk2ekuscbjG6454BmdTyS0cvBECiyczECf-oPHgSukSzXfbujM61eaHN-vvGmeFI7LMQIA4rISOzED7F32-PGL6Y4j7VDF8ZpJJWRTy_9Z9Gs9wqciFOGHRF7Seap4wqFX2wr3wAVgMUYzsrMcZVNE-_uDns5tDDdf_xQ4XuJMQ3uLLfy7V4rw&expires_in=0&user_id=646341532">
                            <div class="valid-feedback">
                                Все хорошо!
                            </div>
                            <div class="invalid-feedback">
                                Введено неверное значение
                            </div>
                            <div id="urlHelp" class="form-text">После получения доступа вставьте ссылку из браузера.</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                    <button type="button" class="btn btn-primary" id="saveChangesButton" disabled>Сохранить изменения</button>
                </div>
            </div>
        </div>
    </div>
<main>
    <div class="container my-5">
        <div class="card">
            <h5 class="card-header">Статус пользователя</h5>
            <div class="card-body">
                <div class="row">
                    <div class="col-sm-3">
                        <div class="list-group">
                            <a href="#" class="list-group-item list-group-item-action active" aria-current="true">
                                Статус пользователя
                            </a>
                            <a href="#" class="list-group-item list-group-item-action">Подключенные группы</a>
                            <a href="#" class="list-group-item list-group-item-action">Администраторы</a>
                        </div>
                    </div>
                    <div class="col-sm-9" style="min-height: 400px;">
                        <div class="row">
                            <?php if(empty($User->getPrivateTocken())){?>
                                <div class="alert alert-warning" role="alert">
                                    <strong>Внимание!</strong>
                                    <p>Для работы с сайтом необходимо разрешить доступ приложению <?=$Core->name?> к API ВКонтакте</p>
                                    <button class="btn btn-primary" type="submit" data-bs-toggle="modal" data-bs-target="#giveAccess">Разрешить доступ ВКонтакте</button>
                                </div>
                            <?php }else{?>
                                    <?php if($User->checkPrivateTocken()){ ?>
                                        <div class="alert alert-success" role="alert">
                                            <strong>Поздравляем!</strong>
                                            <p>Теперь Вы можете <a href="/main/groups">добавить группу</a></p>
                                        </div>
                                    <?php }else{?>
                                    <div class="alert alert-danger" role="alert">
                                        <strong>Внимание!</strong>
                                        <p>Ваш токен устарел.</p>
                                        <button class="btn btn-primary" type="submit" data-bs-toggle="modal" data-bs-target="#giveAccess">Обновить доступ ВКонтакте</button>
                                    </div>
                                    <?php }?>
                            <?php }?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
    const modal = new bootstrap.Modal(document.getElementById("giveAccess"));
    document.addEventListener("DOMContentLoaded", function () {
        const inputUrl = document.getElementById("inputUrl");
        const saveChangesButton = document.getElementById("saveChangesButton");
        const urlForm = document.getElementById("urlForm");

        inputUrl.addEventListener("input", validateInput);
        saveChangesButton.addEventListener("click", sendDataToServer);

        function validateInput() {
            const inputValue = inputUrl.value;
            const codePattern = /blank.html#access_token=[a-zA-Z0-9]+/;

            if (codePattern.test(inputValue)) {
                inputUrl.classList.remove("is-invalid");
                inputUrl.classList.add("is-valid");
                saveChangesButton.removeAttribute("disabled");
            } else {
                inputUrl.classList.remove("is-valid");
                inputUrl.classList.add("is-invalid");
                saveChangesButton.setAttribute("disabled", "disabled");
            }
        }

        function sendDataToServer() {
            const inputValue = inputUrl.value;
            const codePattern = /blank.html#access_token=[a-zA-Z0-9]+/;

            if (codePattern.test(inputValue)) {
                const formData = new FormData(urlForm);

                const xhr = new XMLHttpRequest();
                xhr.open("POST", "/modules/main/update_user_status.php", true);

                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);

                            // Закрыть модальное окно
                            modal.hide();

                            if (response.status === "success") {
                                // Отобразить уведомление об успешной операции
                                showShortSuccessToast("Доступ к ВКонтакте разрешён");
                                setTimeout(() => { location.href = '/main' }, 2000);
                            } else if (response.status === "error" && response.errors) {
                                // Отобразить уведомление ошибках
                                showShortErrorToast(response.errors);
                            }
                        } catch (error) {
                            console.error("Ошибка при обработке ответа:", error);
                        }
                    }
                };

                xhr.send(formData);
            }
        }
    });
</script>
<?php
include_once($_SERVER["DOCUMENT_ROOT"].'/style/foot.php');
?>