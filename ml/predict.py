#!/usr/bin/env python3
"""
DROP GUARD — Machine Learning Analytics Engine
Author: Demeterio, Pete Axle / Palma, Jose Paulo / Ramos, Lance / Zamora, Prince John
WLC Capstone Project (June 2026)

Dual-mode configuration:
1. Random Forest Mode: loads model.pkl + model_metadata.json when present.
2. Heuristic Mode (Fallback): Tinto's Student Integration Model deterministic scoring.

Interactive Commands:
  --test                     Runs dependency checks and baseline mock predictions.
  --train <dataset.csv>      Trains Random Forest and writes model.pkl + metadata.
"""

from __future__ import annotations

import json
import os
import sys
import time

MODEL_FEATURES = [
    "gpa",
    "absences",
    "attendance_rate",
    "consecutive_absences",
    "subject_avg",
    "subject_min",
    "failing_subjects",
    "quarter",
]


def clamp(v: float, lo: float, hi: float) -> float:
    return max(lo, min(hi, v))


def safe_float(value: object, default: float = 0.0) -> float:
    try:
        if value is None:
            return default
        if isinstance(value, str) and value.strip() == "":
            return default
        return float(value)
    except (TypeError, ValueError):
        return default


def safe_int(value: object, default: int = 0) -> int:
    try:
        if value is None:
            return default
        if isinstance(value, str) and value.strip() == "":
            return default
        return int(value)
    except (TypeError, ValueError):
        return default


def parse_payload_thresholds(payload: dict) -> tuple[float, float]:
    """
    Accept threshold keys from PHP orchestrator (canonical) and legacy/alternate shapes.
    Canonical (app/ml.php): thresholds.low_max, thresholds.high_min
    Alternate: settings.risk_low_max, settings.risk_high_min
    """
    thresholds = payload.get("thresholds") or {}
    settings = payload.get("settings") or {}

    low_raw = thresholds.get("low_max")
    if low_raw is None:
        low_raw = settings.get("risk_low_max")
    high_raw = thresholds.get("high_min")
    if high_raw is None:
        high_raw = settings.get("risk_high_min")

    low_max = safe_float(low_raw, 0.40)
    high_min = safe_float(high_raw, 0.70)
    low_max = clamp(low_max, 0.0, 1.0)
    high_min = clamp(high_min, 0.0, 1.0)
    if high_min < low_max:
        high_min = low_max
    return low_max, high_min


def extract_model_features(student: dict) -> dict:
    """Normalize one student dict into MODEL_FEATURES with safe defaults."""
    total_days = safe_float(student.get("total_school_days"), 0.0)
    days_present = safe_float(student.get("days_present"), 0.0)
    if total_days > 0.0:
        attendance_rate = clamp(days_present / total_days, 0.0, 1.0)
    else:
        attendance_rate = 1.0

    return {
        "gpa": clamp(safe_float(student.get("gpa"), 0.0), 0.0, 100.0),
        "absences": max(0.0, safe_float(student.get("absences"), 0.0)),
        "attendance_rate": attendance_rate,
        "consecutive_absences": max(0.0, safe_float(student.get("consecutive_absences"), 0.0)),
        "subject_avg": clamp(safe_float(student.get("subject_avg"), 0.0), 0.0, 100.0),
        "subject_min": clamp(safe_float(student.get("subject_min"), 0.0), 0.0, 100.0),
        "failing_subjects": max(0.0, safe_float(student.get("failing_subjects"), 0.0)),
        "quarter": max(1, safe_int(student.get("quarter"), 1)),
    }


def student_is_empty_record(student: dict) -> bool:
    """True when core academic/attendance signals are all unset/zero."""
    features = extract_model_features(student)
    quarter = student.get("quarter")
    has_quarter = quarter is not None and str(quarter).strip() != ""
    return (
        not has_quarter
        and features["gpa"] <= 0.0
        and features["absences"] <= 0.0
        and features["failing_subjects"] <= 0.0
        and features["subject_avg"] <= 0.0
        and features["subject_min"] <= 0.0
        and safe_float(student.get("days_present"), 0.0) <= 0.0
        and safe_float(student.get("total_school_days"), 0.0) <= 0.0
    )


def load_random_forest_model():
    """Return (model, metadata) or (None, None) if unavailable."""
    try:
        import joblib

        base = os.path.dirname(os.path.abspath(__file__))
        model_path = os.path.join(base, "model.pkl")
        meta_path = os.path.join(base, "model_metadata.json")

        if os.path.exists(model_path) and os.path.exists(meta_path):
            model = joblib.load(model_path)
            with open(meta_path, "r", encoding="utf-8") as handle:
                metadata = json.load(handle)
            return model, metadata
    except Exception:
        pass
    return None, None


def calculate_heuristic_score(student: dict) -> tuple[float, list[str]]:
    """
    Tinto-aligned fallback engine with human-readable risk_factors for counselors.
    Returns (probability_score, risk_factors).
    """
    gpa = float(student.get("gpa") or 0.0)
    absences = float(student.get("absences") or 0.0)
    days_present = float(student.get("days_present") or 0.0)
    total_days = float(student.get("total_school_days") or 0.0)
    consecutive_absences = float(student.get("consecutive_absences") or 0.0)
    failing_subjects = float(student.get("failing_subjects") or 0.0)
    subject_min = float(student.get("subject_min") or 0.0)
    subject_avg = float(student.get("subject_avg") or 0.0)

    gpa = clamp(gpa, 0.0, 100.0)
    absences = max(0.0, absences)
    consecutive_absences = max(0.0, consecutive_absences)
    failing_subjects = max(0.0, failing_subjects)
    subject_min = clamp(subject_min, 0.0, 100.0) if subject_min > 0 else 0.0
    subject_avg = clamp(subject_avg, 0.0, 100.0) if subject_avg > 0 else 0.0

    risk_factors: list[str] = []

    normalized_absences = min(1.0, absences / 60.0)
    inverse_gpa = 1.0 - (gpa / 100.0)
    base_risk = (0.65 * normalized_absences) + (0.35 * inverse_gpa)

    if total_days > 0:
        attendance_ratio = clamp(days_present / total_days, 0.0, 1.0)
        inverse_attendance = 1.0 - attendance_ratio
        blended_score = (0.55 * base_risk) + (0.45 * inverse_attendance)
        if attendance_ratio < 0.85:
            risk_factors.append(
                f"Low overall attendance rate ({round(attendance_ratio * 100, 1)}%)"
            )
    else:
        blended_score = base_risk

    if subject_min > 0 and subject_min < 75.0:
        subj_min_norm = subject_min / 100.0
        blended_score = clamp(blended_score + 0.10 * (1.0 - subj_min_norm), 0.0, 1.0)
        risk_factors.append(
            f"Failing grade registered in at least one subject ({round(subject_min, 1)})"
        )
    if subject_avg > 0:
        subj_avg_norm = subject_avg / 100.0
        blended_score = clamp(blended_score + 0.05 * (1.0 - subj_avg_norm), 0.0, 1.0)
    if failing_subjects > 0:
        blended_score = clamp(blended_score + min(0.18, 0.06 * failing_subjects), 0.0, 1.0)
        risk_factors.append(
            f"Multi-course failure profile detected ({int(failing_subjects)} failing subjects)"
        )

    streak_factor = min(1.0, consecutive_absences / 10.0)
    final_score = (0.85 * blended_score) + (0.15 * streak_factor)

    if consecutive_absences >= 3:
        risk_factors.append(
            f"Active consecutive absence streak ({int(consecutive_absences)} days)"
        )
    if gpa > 0 and gpa < 75.0:
        risk_factors.append(f"Critical cumulative GPA ({round(gpa, 1)})")
    elif gpa <= 0 and absences >= 10:
        risk_factors.append("Elevated absences with limited grade data on file.")

    if not risk_factors and final_score > 0.40:
        risk_factors.append("General cumulative attendance and academic risk trends.")

    return clamp(final_score, 0.0, 1.0), risk_factors


def classify_risk_level(score: float, low_max: float, high_min: float) -> str:
    if score < low_max:
        return "Low"
    if score <= high_min:
        return "Moderate"
    return "High"


def build_prediction(
    student: dict,
    score: float,
    low_max: float,
    high_min: float,
    risk_factors: list[str] | None = None,
) -> dict:
    quarter = student.get("quarter")
    return {
        "student_id": int(student.get("student_id") or 0),
        "grade_level": student.get("grade_level") or "",
        "risk_score": round(score, 4),
        "score": round(score, 4),
        "risk_level": classify_risk_level(score, low_max, high_min),
        "quarter": int(quarter) if quarter is not None and str(quarter).strip() != "" else None,
        "risk_factors": risk_factors or [],
        "is_empty_record": student_is_empty_record(student),
    }


def predict_random_forest_score(rf_model, features: dict) -> float:
    """Score one student row; raises if the model cannot produce a probability."""
    import pandas as pd

    single_row = pd.DataFrame([features])[MODEL_FEATURES]
    proba = rf_model.predict_proba(single_row)
    if proba.shape[1] > 1:
        return float(proba[0][1])
    return float(proba[0][0])


def run_diagnostics() -> None:
    start_time = time.perf_counter()
    diagnostics = {
        "success": True,
        "environment": {
            "python_version": sys.version,
            "pandas_available": False,
            "scikit_learn_available": False,
            "joblib_available": False,
        },
        "model_files": {
            "model_pkl_exists": os.path.exists(
                os.path.join(os.path.dirname(__file__), "model.pkl")
            ),
            "metadata_exists": os.path.exists(
                os.path.join(os.path.dirname(__file__), "model_metadata.json")
            ),
        },
        "test_run": {},
    }

    try:
        import pandas  # noqa: F401

        diagnostics["environment"]["pandas_available"] = True
    except ImportError:
        pass

    try:
        import sklearn  # noqa: F401

        diagnostics["environment"]["scikit_learn_available"] = True
    except ImportError:
        pass

    try:
        import joblib  # noqa: F401

        diagnostics["environment"]["joblib_available"] = True
    except ImportError:
        pass

    mock_student = {
        "gpa": 70.0,
        "absences": 15.0,
        "days_present": 45.0,
        "total_school_days": 60.0,
        "consecutive_absences": 5.0,
        "failing_subjects": 2.0,
        "subject_min": 68.0,
        "subject_avg": 72.0,
    }
    score, factors = calculate_heuristic_score(mock_student)
    diagnostics["test_run"] = {
        "score": score,
        "risk_level": classify_risk_level(score, 0.40, 0.70),
        "explanations": factors,
    }

    diagnostics["performance_telemetry"] = {
        "execution_time_ms": round((time.perf_counter() - start_time) * 1000, 2),
        "engine_mode": "production-hardened-verification",
    }

    print(json.dumps(diagnostics, indent=2))


def train_new_model(csv_path: str) -> None:
    try:
        import joblib
        import pandas as pd
        from sklearn.ensemble import RandomForestClassifier
        from sklearn.metrics import accuracy_score, f1_score, precision_score, recall_score
        from sklearn.model_selection import train_test_split

        if not os.path.exists(csv_path):
            print(json.dumps({"success": False, "error": f"CSV dataset not found at: {csv_path}"}))
            return

        df = pd.read_csv(csv_path)

        if "attendance_rate" not in df.columns:
            if "days_present" in df.columns and "total_school_days" in df.columns:
                df["attendance_rate"] = df.apply(
                    lambda row: (
                        float(row["days_present"]) / float(row["total_school_days"])
                        if float(row.get("total_school_days") or 0) > 0
                        else 1.0
                    ),
                    axis=1,
                )
            else:
                df["attendance_rate"] = 1.0

        required_cols = MODEL_FEATURES + ["dropped_out"]
        for col in required_cols:
            if col not in df.columns:
                print(
                    json.dumps(
                        {"success": False, "error": f"Missing required training column: {col}"}
                    )
                )
                return

        x = df[MODEL_FEATURES]
        y = df["dropped_out"]

        should_stratify = None
        if len(y) > 10 and y.nunique() > 1:
            min_class_count = int(y.value_counts().min())
            if min_class_count >= 2:
                should_stratify = y

        x_train, x_test, y_train, y_test = train_test_split(
            x, y, test_size=0.20, random_state=42, stratify=should_stratify
        )

        rf = RandomForestClassifier(n_estimators=100, random_state=42, max_depth=8)
        rf.fit(x_train, y_train)

        predictions = rf.predict(x_test)
        metadata = {
            "trained_at": "June 2026",
            "model_type": "Random Forest Classifier",
            "features": MODEL_FEATURES,
            "metrics": {
                "accuracy": round(float(accuracy_score(y_test, predictions)), 4),
                "precision": round(float(precision_score(y_test, predictions, zero_division=0)), 4),
                "recall": round(float(recall_score(y_test, predictions, zero_division=0)), 4),
                "f1_score": round(float(f1_score(y_test, predictions, zero_division=0)), 4),
            },
        }

        base = os.path.dirname(os.path.abspath(__file__))
        joblib.dump(rf, os.path.join(base, "model.pkl"))
        with open(os.path.join(base, "model_metadata.json"), "w", encoding="utf-8") as handle:
            json.dump(metadata, handle, indent=2)

        print(
            json.dumps(
                {
                    "success": True,
                    "message": "Model trained and saved successfully.",
                    "metrics": metadata["metrics"],
                }
            )
        )
    except Exception as exc:
        print(json.dumps({"success": False, "error": f"Model training crash: {exc}"}))


def main() -> int:
    if len(sys.argv) > 1:
        flag = sys.argv[1]
        if flag == "--test":
            run_diagnostics()
            return 0
        if flag == "--train":
            if len(sys.argv) < 3:
                print(json.dumps({"success": False, "error": "Missing CSV dataset file path."}))
                return 2
            train_new_model(sys.argv[2])
            return 0

    try:
        raw = sys.stdin.read()
        if not raw.strip():
            sys.stdout.write(json.dumps({"success": False, "error": "Empty input stream received."}))
            return 2

        payload = json.loads(raw)
        students = payload.get("students") or []
        low_max, high_min = parse_payload_thresholds(payload)

        rf_model, rf_meta = load_random_forest_model()
        results: list[dict] = []
        mode = "heuristic"
        model_metrics = None
        batch_start = time.perf_counter()

        use_rf = rf_model is not None and rf_meta is not None
        if use_rf:
            mode = "random_forest"
            model_metrics = rf_meta.get("metrics")

        processed = [extract_model_features(student) for student in students] if use_rf else []

        for idx, student in enumerate(students):
            heuristic_score, factors = calculate_heuristic_score(student)
            if use_rf:
                try:
                    score = predict_random_forest_score(rf_model, processed[idx])
                except Exception:
                    score = heuristic_score
            else:
                score = heuristic_score

            results.append(build_prediction(student, score, low_max, high_min, factors))

        output = {
            "success": True,
            "mode": mode,
            "metrics": model_metrics,
            "predictions": results,
            "performance_telemetry": {
                "execution_time_ms": round((time.perf_counter() - batch_start) * 1000, 2),
                "student_count": len(students),
            },
        }
        sys.stdout.write(json.dumps(output))
        return 0

    except json.JSONDecodeError as exc:
        sys.stderr.write(f"Invalid JSON input: {exc}\n")
        sys.stdout.write(json.dumps({"success": False, "error": f"Invalid JSON input: {exc}"}))
        return 2
    except Exception as exc:
        sys.stderr.write(f"ML Processing Failure: {exc}\n")
        sys.stdout.write(json.dumps({"success": False, "error": f"ML Processing Failure: {exc}"}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
