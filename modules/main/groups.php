<?php
$title = 'Подключенные группы';
include_once($_SERVER["DOCUMENT_ROOT"].'/style/head.php');

if(!isset($User)){
    header('location:/');
}
?>
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
                                            <tbody class="table-group-divider">
                                            <tr>
                                                <th scope="row"><img width="25px" height="25px" src="<?=$scifacts->avatar?>"></th>
                                                <td><?=$scifacts->name?></td>
                                                <td></td>
                                                <td><?=$scifacts->members?></td>
                                                <td><?=$scifacts->type?></td>
                                                <td>
                                                    <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modalDel" data-bs-target="#confirmModal" data-group-id="" data-group-name="">
                                                        <i class="bi bi-trash3"></i>
                                                    </button>
                                                </td>
                                            </tr>
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
<?php
include_once($_SERVER["DOCUMENT_ROOT"].'/style/foot.php');
