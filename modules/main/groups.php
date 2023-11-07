<?php
$title = 'Подключенные группы';
include_once($_SERVER["DOCUMENT_ROOT"].'/style/head.php');

if(!isset($User)){
    header('location:/');
}
?>
    <style>
        /* Стиль анимации для появления строк таблицы */
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        /* Применение анимации к строкам таблицы */
        #groupsTableBody tr {
            animation: fadeIn 0.5s ease-in-out;
        }
    </style>
    <main>
        <div class="container my-5">
            <div class="card">
                <h5 class="card-header"><?=$title?></h5>
                <div class="card-body">
                    <div class="row">
                        <div class="col-sm-3">
                            <div class="list-group">
                                <a href="/main" class="list-group-item list-group-item-action">
                                    Статус пользователя
                                </a>
                                <a href="/main/groups" class="list-group-item list-group-item-action active" aria-current="true">Подключенные группы</a>
                                <a href="#" class="list-group-item list-group-item-action">Администраторы</a>
                            </div>
                        </div>
                        <div class="col-sm-9" style="min-height: 400px;">
                            <div class="row">
                                <?php
                                if(Groups::countGroupsOnAdmin($db, $User) == 0){
                                    ?>
                                    <div class="alert alert-danger" role="alert">
                                        <p>У Вас не добавлено ни одной группы!</p>
                                    </div>
                                    <?php
                                }else{
                                    $scifacts = new Groups(1, $User);
                                    ?>
                                        <table class="table table-sm table-striped table-hover">
                                            <thead>
                                            <tr>
                                                <th scope="col"></th>
                                                <th scope="col">Название</th>
                                                <th scope="col">Администраторы</th>
                                                <th scope="col">Подписчиков</th>
                                                <th scope="col">Тип страницы</th>
                                                <th scope="col"></th>
                                            </tr>
                                            </thead>
                                            <tbody class="table-group-divider" id="groupsTableBody">

                                            </tbody>
                                        </table>
                                <?php }?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

<script>
        // Функция для обновления данных в таблице
        function updateData() {
            // Создаем объект XMLHttpRequest
            var xhr = new XMLHttpRequest();

            // Настройка запроса
            xhr.open("GET", "/modules/main/get_groups.php", true);

            // Обработчик события изменения состояния запроса
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    // Получаем данные из ответа сервера (должны быть в формате JSON)
                    var data = JSON.parse(xhr.responseText);

                    // Получаем ссылку на tbody элемент таблицы
                    var tableBody = document.getElementById("groupsTableBody");

                    // Очищаем содержимое tbody
                    tableBody.innerHTML = "";

                    // Заполняем таблицу данными из полученного JSON
                    for (var i = 0; i < data.length; i++) {
                        var row = tableBody.insertRow();

                        var avaCell = row.insertCell(0);
                        var nameCell = row.insertCell(1);
                        var adminsCell = row.insertCell(2);
                        var membersCell = row.insertCell(3);
                        var typeCell = row.insertCell(4);
                        var actionCell = row.insertCell(5);

                        avaCell.innerHTML = "<a href='https://vk.com/" + data[i].screen_name + "'><img width='25px' height='25px' src='" + data[i].avatar + "'></a>";
                        nameCell.textContent = data[i].name;
                        adminsCell.textContent = data[i].admins; //Админы, нужно добавить потом
                        membersCell.textContent = data[i].members;
                        typeCell.textContent = data[i].type;
                        actionCell.innerHTML = data[i].Action;
                        row.classList.add("fadeIn");
                    }
                    //setupButtonHandlers();
            }
        };

            // Отправляем запрос
            xhr.send();
    }

        // Вызываем функцию обновления данных при загрузке страницы
        updateData();
</script>
<?php
include_once($_SERVER["DOCUMENT_ROOT"].'/style/foot.php');
