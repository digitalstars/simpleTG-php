#include <iostream>
#include <stdexcept>
#include <cstdint>
#include <cstdlib>

namespace PrimeModule {
    // Функция для нахождения наибольшего общего делителя (gcd)
    inline uint64_t gcd(uint64_t a, uint64_t b) {
        while (a != 0 && b != 0) {
            while ((b & 1) == 0) {
                b >>= 1;
            }
            while ((a & 1) == 0) {
                a >>= 1;
            }
            if (a > b) {
                a -= b;
            } else {
                b -= a;
            }
        }
        return b == 0 ? a : b;
    }

    // Функция факторизации числа
    uint32_t factorize(uint64_t number) {
        int32_t it = 0, i, j;
        uint64_t g = 0;
        for (i = 0; i < 3 || it < 1000; i++) {
            uint64_t t = ((rand() & 15) + 17) % number;  // Заменили lrand48 на rand
            uint64_t x = (long long)rand() % (number - 1) + 1, y = x;
            int32_t lim = 1 << (i + 18);
            for (j = 1; j < lim; j++) {
                ++it;
                uint64_t a = x, b = x, c = t;
                while (b) {
                    if (b & 1) {
                        c += a;
                        if (c >= number) {
                            c -= number;
                        }
                    }
                    a += a;
                    if (a >= number) {
                        a -= number;
                    }
                    b >>= 1;
                }
                x = c;
                uint64_t z = x < y ? number + x - y : x - y;
                g = PrimeModule::gcd(z, number);
                if (g != 1) {
                    break;
                }
                if (!(j & (j - 1))) {
                    y = x;
                }
            }
            if (g > 1 && g < number) {
                break;
            }
        }

        if (g > 1 && g < number) {
            return (uint32_t)g;
        } else {
            throw std::runtime_error("Factorization failed!");
        }
    }
}

// Функция для факторизации без использования потоков
extern "C" int32_t factorizeFFI(const char *number) {
    try {
        uint64_t num = std::stoull(number);  // Преобразуем строку в число

        // Выполняем факторизацию
        uint32_t result = PrimeModule::factorize(num);
        std::cout << "Factor: " << result << std::endl;
    } catch (...) {
        return -1;  // Ошибка
    }
    return 0;  // Успешное выполнение
}
