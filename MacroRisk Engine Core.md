Математическое ядро системы (ACMF 4.1.0.0 / MacroRisk Engine Core)

```markdown
# Математическое ядро системы оценки макроэкономических рисков
**Версия модуля:** ACMF 4.1.0.0 / MacroRisk Engine Core  
**Дата:** 2026-08-04  
**Статус:** REDUCED CALIBRATION CORE WITH MIGRATION REPLENISHMENT LAYER  

---

## 1. Введение и область применения

Данный документ фиксирует математический аппарат Риск-Движка (Risk Engine) и демографического слоя мониторинга. Математическое ядро детерминировано, изолировано от окружения и гарантирует абсолютную воспроизводимость результатов ($100\%$ совпадение при одинаковых входных данных).

---

## 2. Арифметика высокой точности (Decimal Precision)

Все расчёты в системе выполняются с использованием точной десятичной арифметики произвольной точности (BCMath). 

- **Промежуточные вычисления:** выполняются с точностью до 8 знаков после запятой ($scale = 8$).
- **Финальное округление:** округление к $DECIMAL(10,4)$ происходит только на этапе сохранения результата.
- **Преобразования:** прямые операции над числами с плавающей точкой (`float`, `double`) в PHP запрещены.

---

## 3. Модель нормализации индикаторов

Каждый сырой индикатор $x_{i,t}$ на дату винтажа $t$ преобразуется в нормализованный балл $s_{i,t} \in [0.0000, 100.0000]$.

### 3.1 Линейно-пороговая нормализация (`threshold_linear`)

Пусть $L_i$ — порог низкого риска (`low_risk_threshold`), $H_i$ — порог высокого риска (`high_risk_threshold`).

1. **Направление `higher_is_riskier` ($H_i > L_i$):**
   $$s_{i,t} = \begin{cases} 
   0.0000, & \text{если } x_{i,t} \le L_i \\ 
   100.0000, & \text{если } x_{i,t} \ge H_i \\ 
   \frac{x_{i,t} - L_i}{H_i - L_i} \times 100, & \text{если } L_i < x_{i,t} < H_i 
   \end{cases}$$

2. **Направление `lower_is_riskier` ($H_i < L_i$):**
   $$s_{i,t} = \begin{cases} 
   0.0000, & \text{если } x_{i,t} \ge L_i \\ 
   100.0000, & \text{если } x_{i,t} \le H_i \\ 
   \frac{L_i - x_{i,t}}{L_i - H_i} \times 100, & \text{если } H_i < x_{i,t} < L_i 
   \end{cases}$$

3. **Направление `distance_from_target_is_riskier`:**
   $$s_{i,t} = \min\left(100.0000, \frac{|x_{i,t} - T_i|}{M_i} \times 100\right)$$
   где $T_i$ — целевое значение (`target_value`), $M_i$ — максимальное отклонение (`max_deviation`).

---

## 4. Динамическая ренормализация и учет весов

### 4.1 Проверка покрытия (Coverage Ratio)
Пусть $\Omega$ — множество всех сконфигурированных индикаторов, $A \subseteq \Omega$ — множество доступных и валидных индикаторов.

$$\text{coverage\_ratio} = \sum_{i \in A} w_i^{\text{orig}}$$

Условие выполнения расчёта: $\text{coverage\_ratio} \ge 60.0000\%$ и $|A| \ge 3$ и $\forall k \in \text{Required}: k \in A$.

### 4.2 Алгоритм взвешивания
1. **Базовый пересчёт доступных весов:**
   $$w_i^{\text{base}} = \frac{w_i^{\text{orig}}}{\sum_{j \in A} w_j^{\text{orig}}} \times 100, \quad \forall i \in A$$

2. **Применение дисконта частоты релиза ($d_i \in (0, 1]$):**
   $$w_i^{\text{disc}} = w_i^{\text{base}} \times d_i$$

3. **Расчёт итогового эффективного веса ($w_i^{\text{eff}}$):**
   $$w_i^{\text{eff}} = \frac{w_i^{\text{disc}}}{\sum_{j \in A} w_j^{\text{disc}}} \times 100$$
   *(Гарантируется, что $\sum_{i \in A} w_i^{\text{eff}} = 100.0000\%$).*

4. **Итоговый Risk Score ($R_t$):**
   $$R_t = \sum_{i \in A} \left( \frac{s_{i,t} \times w_i^{\text{eff}}}{100} \right)$$

---

## 5. Демографический метаболический слой (ACMF 4.1.0.0 Core)

Демографический контур описывается вектором когорт $P(t) = \big(P_1(t), P_2(t), P_3(t)\big)^T$, где:
- $P_1$: 0–14 лет (дети/подростки).
- $P_2$: 15–64 года (трудоспособное ядро).
- $P_3$: 65+ лет (пожилое население).

### 5.1 Уравнения динамики когорт
$$P_1(t+1) = P_1(t) + \text{Births}(t) - \text{Aging}_{12}(t) - \text{Deaths}_1(t) + M_1(t)$$
$$P_2(t+1) = P_2(t) + \text{Aging}_{12}(t) - \text{Aging}_{23}(t) - \text{Deaths}_2(t) + M_2(t) + \text{LabourRetention}(t)$$
$$P_3(t+1) = P_3(t) + \text{Aging}_{23}(t) - \text{Deaths}_3(t) + M_3(t)$$

### 5.2 Возрастные переходы
$$\text{Aging}_{12}(t) = \gamma_{\text{scale}} \times \frac{P_1(t)}{15}$$
$$\text{Aging}_{23}(t) = \gamma_{\text{scale}} \times \frac{P_2(t)}{50}$$
*(где $\gamma_{\text{scale}} \approx 0.9543$ — калиброванный масштаб взросления).*

### 5.3 Миграционный слой восполнения ($g_{10}$)
Миграционное пополнение по когортам рассчитывается на основе внешних данных с калибруемым коэффициентом $g_{10}$:

$$\text{IntlOther}(t) = \text{NetInternational}(t) + \text{OtherInternational}(t)$$

$$M_1^{\text{raw}}(t) = 0.15 \times \text{IntlOther}(t) + \text{Interprovincial}_{0\text{--}17}(t)$$
$$M_2^{\text{raw}}(t) = 0.80 \times \text{IntlOther}(t) + \text{Interprovincial}_{18\text{--}64}(t)$$
$$M_3^{\text{raw}}(t) = 0.05 \times \text{IntlOther}(t) + \text{Interprovincial}_{65+}(t)$$

Итоговый миграционный приток:
$$M_k(t) = g_{10} \times M_k^{\text{raw}}(t), \quad k \in \{1, 2, 3\}$$

Внутреннее удержание рынка труда (Labour Retention Residual):
$$\text{LabourRetention}(t) = 0.0015 \times (g_9 - 1) \times P_2(t)$$

*При $g_{10} \approx 1.1112$, показатель $g_9$ сходится к $1.0065$, что доказывает структурную зависимость прироста когорты $P_2$ от внешней миграции.*
