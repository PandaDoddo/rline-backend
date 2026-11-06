# Планирование backend R-Line

Подготовленный каркас Laravel и инструкция запуска Docker находятся в [backend/README.md](../README.md).

Срез возможностей frontend от 06.11.2026, коммит `7559faf`.

| Документ | Назначение |
| --- | --- |
| [FEATURES.md](FEATURES.md) | Фичи, пользовательские действия, данные и состояние реализации во frontend |
| [DECOMPOSITION.md](DECOMPOSITION.md) | Индекс полной декомпозиции и первый сквозной сценарий |
| [ROADMAP.md](ROADMAP.md) | Последовательность поставок, зависимости и покрытие всех фич |
| [STACK_OPTIONS.md](STACK_OPTIONS.md) | Оценка Laravel, Symfony и обвязок, предлагаемый стек и открытые решения |
| [decomposition](decomposition) | 235 задач по фичам и 12 общих задач, критерии приёмки и открытые решения |

Карта составлена по текущим TSX-страницам, модели рабочего пространства и расчётным модулям. Состояние frontend описывает поведение в браузере. Серверные операции предстоит реализовать.

Архитектурные решения Laravel, изоляция организаций и перенос расчётных ядер описаны в [существующем плане архитектуры](architecture.md). Общий перечень работ находится в [WORKPLAN.md](https://github.com/PandaDoddo/rline/blob/main/docs/planning/WORKPLAN.md). Здесь фиксируется функциональный объём, который потребуется связать с этой архитектурой.

Правила предметной области сверяются с [AGENTS.md](https://github.com/PandaDoddo/rline/blob/main/AGENTS.md), [ТЗ](https://github.com/PandaDoddo/rline/blob/main/spec/RISK-Line_TZ.md) и [BACKLOG.md](https://github.com/PandaDoddo/rline/blob/main/spec/BACKLOG.md). В BACKLOG есть исторические записи, которые уже частично перекрыты кодом. Перед переносом каждой задачи её состояние нужно проверить по реализации.

Фичи обозначены `F01`–`F29`, детальные задачи — `Fxx.yy`, общие механизмы — `X01`–`X12`. Первый сквозной сценарий `B0`–`B8` объединяет соответствующие задачи в проверяемую поставку. Порядок реализации является предложением для обсуждения.

Backend и эти документы ведутся отдельно в [PandaDoddo/rline-backend](https://github.com/PandaDoddo/rline-backend). Основной репозиторий с frontend — [PandaDoddo/rline](https://github.com/PandaDoddo/rline).

Аудит происхождения отображаемых данных перенесён в [документацию frontend](https://github.com/PandaDoddo/rline/blob/main/frontend/docs/DATA_ORIGINS_AUDIT.md). Исправления отложены и относятся к задачам frontend.
