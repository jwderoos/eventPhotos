"""Fixed allowlist vocabularies. Adding a term is a spec change (see build spec)."""

VOCABULARY: dict[str, set[str]] = {
    "clothing_colors": {
        "black", "white", "grey", "red", "orange", "yellow",
        "green", "blue", "purple", "pink", "brown", "beige",
    },
    "clothing_types": {
        "t-shirt", "long-sleeve shirt", "jacket", "hoodie/sweater",
        "dress", "shorts", "trousers", "skirt", "hat/cap",
    },
    "scenes": {
        "start", "finish-line", "on-course/running",
        "water-station", "crowd/spectators", "medal/podium",
    },
}
