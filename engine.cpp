// MITS EduNova scoring engine - C++17
// Usage: engine.exe score <total> <correct> <wrong> <negative_penalty>
#include <iostream>
#include <iomanip>
#include <cstdlib>
#include <cmath>
int main(int argc, char* argv[]) {
    if (argc != 6 || std::string(argv[1]) != "score") {
        std::cerr << "Usage: engine score <total> <correct> <wrong> <negative_penalty>\n";
        return 2;
    }
    try {
        int total = std::stoi(argv[2]), correct = std::stoi(argv[3]), wrong = std::stoi(argv[4]);
        double penalty = std::stod(argv[5]);
        if (total < 0 || correct < 0 || wrong < 0 || correct + wrong > total || penalty < 0 || penalty > 1) return 3;
        int unanswered = total - correct - wrong;
        double score = std::max(0.0, correct - wrong * penalty);
        double accuracy = total ? (100.0 * correct / total) : 0.0;
        std::cout << std::fixed << std::setprecision(2)
                  << "{\"score\":" << score << ",\"total\":" << total
                  << ",\"correct\":" << correct << ",\"wrong\":" << wrong
                  << ",\"unanswered\":" << unanswered << ",\"accuracy\":" << accuracy << "}";
    } catch (...) { return 4; }
    return 0;
}
