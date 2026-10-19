#!/usr/bin/env python3
"""Build demo/new-patient-guide.pdf (fictional BrightSmile Dental guide).

Requires: pip install reportlab
Usage:    python3 bin/make-guide-pdf.py
"""
import os

from reportlab.lib.colors import HexColor
from reportlab.lib.pagesizes import letter
from reportlab.pdfgen import canvas

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "demo", "new-patient-guide.pdf")
BRAND = HexColor("#0e7c86")

SECTIONS = [
    ("Welcome to BrightSmile Dental", [
        "Thank you for choosing BrightSmile Dental. This guide explains how to prepare for your first",
        "visit, what to bring and what happens during the appointment.",
    ]),
    ("Before your visit", [
        "You can fill out the new patient forms online before your visit. We email you a secure link",
        "after you book. If you prefer paper forms, please arrive 15 minutes early to complete them.",
        "Tell us about any medical conditions, medications or allergies so we can treat you safely.",
    ]),
    ("What to bring to your first appointment", [
        "- A photo ID such as a driver's license or passport",
        "- Your dental insurance card, if you have one",
        "- A list of your medications and allergies",
        "- Dental x-rays from the last two years, if your previous dentist can send them",
        "- For children under 18: a parent or legal guardian must come to the first visit",
    ]),
    ("What happens at your first visit", [
        "Your first appointment takes about 90 minutes. We review your health history, take digital",
        "x-rays, do a full exam with an oral cancer screening and a professional cleaning. Then the",
        "dentist explains what we found and gives you a written treatment plan with an estimate.",
    ]),
    ("Payment and insurance", [
        "Payment is due at the time of your visit. If you have insurance, we bill your plan and you",
        "pay only your share. Ask us about the BrightSmile membership plan and 0% payment plans.",
    ]),
    ("Cancellations", [
        "Please give us 24 hours notice if you need to reschedule. Late cancellations may have a fee.",
    ]),
    ("After your visit", [
        "Mild gum tenderness after a cleaning is normal and fades within a day. Call us if anything",
        "feels wrong. Your next checkup is usually booked six months later.",
    ]),
    ("Getting here", [
        "BrightSmile Dental, 128 Harbor Lane, Suite 200, Lakeview. Free parking behind the building.",
        "Phone (415) 555-0142. Email hello@brightsmile.example.",
    ]),
]


def main():
    c = canvas.Canvas(OUT, pagesize=letter, pageCompression=1)
    c.setTitle("New Patient Guide - BrightSmile Dental (fictional demo)")
    c.setAuthor("BrightSmile Dental (fictional)")
    width, height = letter

    c.setFillColor(BRAND)
    c.rect(0, height - 110, width, 110, stroke=0, fill=1)
    c.setFillColor(HexColor("#ffffff"))
    c.setFont("Helvetica-Bold", 26)
    c.drawString(54, height - 62, "New Patient Guide")
    c.setFont("Helvetica", 13)
    c.drawString(54, height - 86, "BrightSmile Dental - Lakeview")

    y = height - 150
    for title, lines in SECTIONS:
        if y < 120:
            c.showPage()
            y = height - 72
        c.setFillColor(BRAND)
        c.setFont("Helvetica-Bold", 14)
        c.drawString(54, y, title)
        y -= 20
        c.setFillColor(HexColor("#1d2327"))
        c.setFont("Helvetica", 10.5)
        for line in lines:
            c.drawString(54, y, line)
            y -= 15
        y -= 12

    c.setFont("Helvetica-Oblique", 8.5)
    c.setFillColor(HexColor("#646970"))
    c.drawString(54, 40, "Fictional demo document for the SiteSage Chat portfolio project. Not a real clinic.")
    c.save()
    print("Wrote", OUT)


if __name__ == "__main__":
    main()
