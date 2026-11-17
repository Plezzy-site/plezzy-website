use axum::{
    body::Body,
    extract::Query,
    http::{header, HeaderValue, StatusCode, Uri},
    response::{IntoResponse, Response},
    routing::{get, post},
    Json, Router,
};
use qrcode::render::svg;
use qrcode::QrCode;
use serde::{Deserialize, Serialize};
use std::{net::SocketAddr, path::PathBuf};
use tower_http::{
    compression::CompressionLayer,
    cors::{Any, CorsLayer},
    services::ServeDir,
};
use tracing::{info, Level};
use tracing_subscriber::FmtSubscriber;

#[derive(Serialize)]
struct HealthResponse {
    status: &'static str,
    server: &'static str,
    version: &'static str,
}

#[derive(Serialize)]
struct PlezzyInfo {
    lan_url: String,
    port: u16,
    engine: &'static str,
}

#[derive(Deserialize)]
struct QrParams {
    url: Option<String>,
}

#[derive(Deserialize)]
struct CartCalcRequest {
    items: Vec<CartItem>,
    promo_code: Option<String>,
}

#[derive(Deserialize, Serialize)]
struct CartItem {
    id: String,
    quantity: u32,
    price: f64,
}

#[derive(Serialize)]
struct CartCalcResponse {
    subtotal: f64,
    discount: f64,
    shipping: f64,
    total: f64,
    currency: &'static str,
    stealth_packaging: &'static str,
}

#[tokio::main]
async fn main() {
    let subscriber = FmtSubscriber::builder()
        .with_max_level(Level::INFO)
        .finish();
    let _ = tracing::subscriber::set_global_default(subscriber);

    let public_dir = PathBuf::from(std::env::var("PLEZZY_PUBLIC_DIR").unwrap_or_else(|_| ".".into()));

    let cors = CorsLayer::new()
        .allow_origin(Any)
        .allow_methods(Any)
        .allow_headers(Any);

    let app = Router::new()
        // API Routes
        .route("/api/health", get(health_check))
        .route("/__plezzy_info", get(plezzy_info))
        .route("/api/qr.svg", get(generate_qr))
        .route("/api/cart/calculate", post(calculate_cart))
        // Static assets serving
        .nest_service("/assets", ServeDir::new(public_dir.join("assets")))
        // Fallback handler for clean URLs and static pages
        .fallback(move |uri: Uri| {
            let p_dir = public_dir.clone();
            async move { handle_page_route(uri, p_dir).await }
        })
        .layer(CompressionLayer::new())
        .layer(cors);

    let port: u16 = std::env::var("PORT")
        .ok()
        .and_then(|p| p.parse().ok())
        .unwrap_or(3000);

    let addr = SocketAddr::from(([0, 0, 0, 0], port));
    info!("🦀 Plezzy Rust Axum Server starting on http://localhost:{}", port);
    info!("🚀 Memory-safe, high-concurrency engine running");

    let listener = tokio::net::TcpListener::bind(addr)
        .await
        .expect("Failed to bind port");

    axum::serve(listener, app).await.expect("Server error");
}

async fn health_check() -> Json<HealthResponse> {
    Json(HealthResponse {
        status: "ok",
        server: "plezzy-rust-axum",
        version: "0.1.0",
    })
}

async fn plezzy_info() -> Json<PlezzyInfo> {
    let lan_ip = local_ip_address::local_ip()
        .map(|ip| ip.to_string())
        .unwrap_or_else(|_| "127.0.0.1".into());

    Json(PlezzyInfo {
        lan_url: format!("http://{}:3000/", lan_ip),
        port: 3000,
        engine: "Rust Axum 0.7",
    })
}

async fn generate_qr(Query(params): Query<QrParams>) -> impl IntoResponse {
    let target = params.url.unwrap_or_else(|| "http://localhost:3000/".into());

    match QrCode::new(target.as_bytes()) {
        Ok(code) => {
            let svg = code
                .render()
                .min_dimensions(200, 200)
                .dark_color(svg::Color("#141414"))
                .light_color(svg::Color("#ffffff"))
                .build();

            Response::builder()
                .status(StatusCode::OK)
                .header(header::CONTENT_TYPE, "image/svg+xml")
                .header(header::CACHE_CONTROL, "no-cache")
                .body(Body::from(svg))
                .unwrap_or_else(|_| StatusCode::INTERNAL_SERVER_ERROR.into_response())
        }
        Err(_) => StatusCode::BAD_REQUEST.into_response(),
    }
}

async fn calculate_cart(Json(req): Json<CartCalcRequest>) -> Json<CartCalcResponse> {
    let subtotal: f64 = req.items.iter().map(|i| i.price * i.quantity as f64).sum();
    let discount = if let Some(code) = req.promo_code {
        if code.trim().eq_ignore_ascii_case("WELCOME10") {
            subtotal * 0.10
        } else {
            0.0
        }
    } else {
        0.0
    };

    let shipping = if subtotal > 1500.0 || subtotal == 0.0 { 0.0 } else { 120.0 };
    let total = (subtotal - discount + shipping).max(0.0);

    Json(CartCalcResponse {
        subtotal,
        discount,
        shipping,
        total,
        currency: "₹",
        stealth_packaging: "100% Plain Unbranded Box (Guaranteed)",
    })
}

async fn handle_page_route(uri: Uri, base_dir: PathBuf) -> Response {
    let path = uri.path();

    // 1. Canonical 301 redirects for .html and /index.html
    if path == "/index.html" {
        return Response::builder()
            .status(StatusCode::MOVED_PERMANENTLY)
            .header(header::LOCATION, "/")
            .body(Body::empty())
            .unwrap();
    }

    if path.ends_with(".html") && !path.starts_with("/assets/") {
        let clean = path.trim_end_matches(".html");
        let target = if clean == "/index" { "/" } else { clean };
        return Response::builder()
            .status(StatusCode::MOVED_PERMANENTLY)
            .header(header::LOCATION, target)
            .body(Body::empty())
            .unwrap();
    }

    // 2. Clean URL resolution
    let file_to_serve = match path {
        "/" => "index.html",
        "/collections" | "/collections/" => "collections.html",
        "/cart" | "/cart/" => "cart.html",
        "/account" | "/account/" => "account.html",
        other => {
            let relative = other.trim_start_matches('/');
            let exact = base_dir.join(relative);
            if exact.is_file() {
                return serve_static_file(&exact).await;
            }
            let with_html = base_dir.join(format!("{}.html", relative));
            if with_html.is_file() {
                return serve_static_file(&with_html).await;
            }
            "index.html" // Single-page app fallback
        }
    };

    let full_path = base_dir.join(file_to_serve);
    serve_static_file(&full_path).await
}

async fn serve_static_file(path: &PathBuf) -> Response {
    match tokio::fs::read(path).await {
        Ok(contents) => {
            let mime = mime_guess::from_path(path).first_or_octet_stream();
            let mut res = Response::builder()
                .status(StatusCode::OK)
                .header(header::CONTENT_TYPE, HeaderValue::from_str(mime.as_ref()).unwrap())
                .body(Body::from(contents))
                .unwrap();

            if path.to_string_lossy().contains("/assets/") {
                res.headers_mut().insert(
                    header::CACHE_CONTROL,
                    HeaderValue::from_static("public, max-age=31536000, immutable"),
                );
            } else {
                res.headers_mut().insert(
                    header::CACHE_CONTROL,
                    HeaderValue::from_static("no-cache, must-revalidate"),
                );
            }
            res
        }
        Err(_) => StatusCode::NOT_FOUND.into_response(),
    }
}

