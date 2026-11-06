"""Definitions loaded into a PyHP session by examples/02_vars_and_functions.php."""


def cart_total(cart, discount=0.0):
    subtotal = sum(item["price"] * item["qty"] for item in cart)
    # vatRate is set from PHP before this function is called.
    return round(subtotal * (1 - discount) * (1 + vatRate), 2)  # noqa: F821


class Invoice:
    def __init__(self, customer):
        self.customer = customer
        self.lines = []

    def add(self, name, price, qty=1):
        self.lines.append({"name": name, "price": price, "qty": qty})

    def total(self):
        return sum(line["price"] * line["qty"] for line in self.lines)

    def __str__(self):
        return f"Invoice for {self.customer}: {len(self.lines)} lines, total {self.total()}"
