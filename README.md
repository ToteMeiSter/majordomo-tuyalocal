# MajorDoMo Tuya Local

Модуль для [MajorDoMo](https://majordomohome.com/) — **локальное управление Tuya-устройствами по LAN** (без облака) через [tinytuya](https://github.com/jasonacox/tinytuya). Опрашивает состояние и шлёт команды напрямую на устройство по его локальному ключу; на основе данных создаёт простые устройства MajorDoMo с управлением из карточки и дашбордов.

## Возможности

- Локальный опрос состояния (DP) и управление (set) без обращения к облаку Tuya.
- Авто-создание простых устройств MajorDoMo и привязка свойств/методов.
- Веб-форма добавления/редактирования/удаления устройств (имя, IP, Device ID, Local Key, версия протокола).
- HTTP-API для дашбордов: `/ajax/tuyalocal.html?op=list` и `?op=set&id=&system=&value=`.
- Профили устройств (DP-карты) по категориям. Поддержан: **`wk`** — терморегулятор/термостат.

## Требования

- MajorDoMo (PHP 8.x).
- Python 3 и `tinytuya`: `pip install tinytuya`.

## Установка

Скопируйте на сервер MajorDoMo:

```
modules/tuyalocal/        → modules/tuyalocal/
templates/tuyalocal/      → templates/tuyalocal/
scripts/cycle_tuyalocal.php → scripts/
```

Установите модуль в панели MajorDoMo (раздел «Модули»), затем перезапустите циклы.

## Настройка

Раздел **Tuya Local** → форма «Добавить устройство»:

| Поле | Описание |
|------|----------|
| Имя | произвольное название |
| Категория | профиль DP-карты (`wk` — терморегулятор) |
| IP | адрес устройства в локальной сети |
| Device ID | идентификатор устройства Tuya |
| Local Key | локальный ключ (из облака Tuya IoT, привязан к устройству) |
| Версия протокола | 3.1 / 3.2 / 3.3 / 3.4 / 3.5 |

`Local Key` получают один раз из [Tuya IoT Platform](https://iot.tuya.com/) для своего устройства.

## Лицензия

[MIT](LICENSE). Использует [tinytuya](https://github.com/jasonacox/tinytuya) (MIT).
