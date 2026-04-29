<?php
// ============================================================
// PATTERN 2: FACTORY — User Creation by Role
// ============================================================
// Cheat sheet: "A single factory class decides WHICH subclass to
//   instantiate and return, based on data it receives."
//   "Return type of factory method is the BASE class, not a
//    specific subclass. if/switch statement inside factory method."
//
// Problem it solves: handleRegister() had an if/elseif chain
// that created donor_profiles, hospitals records, etc. inline.
// Adding a new role meant editing auth.php. Now you add a
// subclass — the caller never changes.
// ============================================================


// BASE CLASS — shared interface that all role creators extend
// (cheat sheet: "all returned classes share the same base class")
abstract class UserCreator {
    protected PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // Shared logic — every role creates the same users row
    public function createBaseUser(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO Users
                (full_name, email, phone, password_hash, role,
                 location, city, region, latitude, longitude)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            sanitize($data['full_name']),
            strtolower(sanitize($data['email'])),
            sanitize($data['phone'] ?? ''),
            password_hash($data['password'], PASSWORD_BCRYPT),
            $data['role'],
            sanitize($data['location'] ?? ''),
            sanitize($data['city']     ?? ''),
            sanitize($data['region']   ?? ''),
            $data['latitude']  ?? null,
            $data['longitude'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    // Role-specific profile creation — each subclass implements this differently
    abstract public function createProfile(int $userId, array $data): void;
}


// SUBCLASS 1 — Donor: inserts a row into donor_profiles
class DonorCreator extends UserCreator {
    public function createProfile(int $userId, array $data): void {
        $stmt = $this->db->prepare("
            INSERT INTO donor_profiles
                (user_id, blood_type, date_of_birth, gender, weight_kg)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $data['blood_type']    ?? 'unknown',
            $data['date_of_birth'] ?? null,
            $data['gender']        ?? null,
            $data['weight_kg']     ?? null,
        ]);
    }
}


// SUBCLASS 2 — Hospital: inserts a row into hospitals
class HospitalCreator extends UserCreator {
    public function createProfile(int $userId, array $data): void {
        $stmt = $this->db->prepare("
            INSERT INTO hospitals
                (user_id, hospital_name, registration_number,
                 hospital_type, address, city, region, latitude, longitude)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            sanitize($data['hospital_name']        ?? $data['full_name']),
            sanitize($data['registration_number']  ?? ''),
            $data['hospital_type']                 ?? 'public',
            sanitize($data['address'] ?? $data['location'] ?? ''),
            sanitize($data['city']   ?? ''),
            sanitize($data['region'] ?? ''),
            $data['latitude']  ?? null,
            $data['longitude'] ?? null,
        ]);
    }
}


// SUBCLASS 3 — Health Worker: no separate profile table required
class HealthWorkerCreator extends UserCreator {
    public function createProfile(int $userId, array $data): void {
        // Health workers share the base users row only — nothing extra to insert
    }
}


// THE FACTORY — the one place that decides which subclass to return
// (cheat sheet: "if/switch inside method; return type is the BASE class")
class UserFactory {

    // Return type is UserCreator (BASE) — caller never sees DonorCreator etc.
    public static function getCreator(string $role, PDO $db): UserCreator {
        switch ($role) {
            case 'donor':         return new DonorCreator($db);
            case 'hospital':      return new HospitalCreator($db);
            case 'health_worker': return new HealthWorkerCreator($db);
            default:
                jsonResponse(false, "Invalid role: $role", [], 422);
                exit;
        }
    }
}
