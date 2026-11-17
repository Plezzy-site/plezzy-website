use wasm_bindgen::prelude::*;
use serde::{Deserialize, Serialize};

#[wasm_bindgen]
pub fn plezzy_engine_info() -> String {
    "🦀 Plezzy Rust WebAssembly Engine v0.1.0 Active".into()
}

#[derive(Serialize, Deserialize)]
pub struct StealthOrderVerification {
    pub order_id: String,
    pub stealth_hash: String,
    pub is_valid: bool,
    pub packaging_guarantee: String,
}

#[wasm_bindgen]
pub fn generate_stealth_hash(order_id: &str, secret_salt: &str) -> String {
    // Fast lightweight FNV-1a non-cryptographic hash for tamper-evident receipts
    let mut hash: u64 = 0xcbf29ce484222325;
    let input = format!("{}:{}:plezzy_stealth", order_id, secret_salt);
    for byte in input.bytes() {
        hash ^= byte as u64;
        hash = hash.wrapping_mul(0x100000001b3);
    }
    format!("PLZ-SEC-{:016X}", hash)
}

#[wasm_bindgen]
pub fn verify_stealth_order(order_id: &str) -> Result<JsValue, JsValue> {
    let stealth_hash = generate_stealth_hash(order_id, "plezzy_vip_token");
    let result = StealthOrderVerification {
        order_id: order_id.to_string(),
        stealth_hash,
        is_valid: order_id.starts_with("PLZ-"),
        packaging_guarantee: "100% Plain Unbranded Box • Zero External Markers".to_string(),
    };

    serde_wasm_bindgen::to_value(&result).map_err(|e| JsValue::from_str(&e.to_string()))
}

#[wasm_bindgen]
pub fn calculate_resonance_frequency(speed_level: u32, dual_motor: bool) -> f32 {
    let base_hz: f32 = match speed_level {
        1 => 45.0,
        2 => 65.0,
        3 => 85.0,
        4 => 110.0,
        5 => 140.0,
        _ => 50.0,
    };

    if dual_motor {
        base_hz * 1.414 // Harmonic resonance multiplier
    } else {
        base_hz
    }
}

